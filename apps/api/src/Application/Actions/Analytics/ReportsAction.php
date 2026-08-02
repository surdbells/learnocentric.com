<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Report;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Super-admin reports engine: generate a persisted snapshot from one of a few
 * platform templates, keep a history, view it, and export it to CSV. All
 * figures are computed live from real data at generation time.
 */
final class ReportsAction
{
    /** Template key => [title, description]. */
    private const TEMPLATES = [
        'platform_overview' => ['Platform Overview', 'Headline counts, engagement and revenue across the whole platform.'],
        'institution_performance' => ['Institution Performance', 'Per-institution users, learners, assessment activity and average score.'],
        'subscriptions' => ['Subscriptions & Revenue', 'Active subscriptions and monthly revenue broken down by plan.'],
        'user_growth' => ['User Growth', 'New users, institutions and assessment activity over the last 6 months.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /platform/reports/templates — the report types that can be generated. */
    public function templates(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $rows = [];
        foreach (self::TEMPLATES as $key => [$title, $desc]) {
            $rows[] = ['type' => $key, 'title' => $title, 'description' => $desc];
        }
        return Json::write($response, ['data' => $rows]);
    }

    /** GET /platform/reports — history of generated reports (newest first). */
    public function list(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $reports = $this->em->getRepository(Report::class)->findBy([], ['createdAt' => 'DESC'], 100);
        return Json::write($response, ['data' => array_map(static fn (Report $r) => $r->toArray(), $reports), 'meta' => ['total' => count($reports)]]);
    }

    /** POST /platform/reports — generate + persist a report of { type }. */
    public function generate(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $body = (array) $request->getParsedBody();
        $type = (string) ($body['type'] ?? '');
        if (!isset(self::TEMPLATES[$type])) {
            return Json::error($response, 'Unknown report type.', 422);
        }

        [$title] = self::TEMPLATES[$type];
        $built = match ($type) {
            'platform_overview' => $this->buildPlatformOverview(),
            'institution_performance' => $this->buildInstitutionPerformance(),
            'subscriptions' => $this->buildSubscriptions(),
            'user_growth' => $this->buildUserGrowth(),
        };

        /** @var User $user */
        $user = $request->getAttribute('user');
        $report = new Report($type, $title);
        $report->setSummary($built['summary']);
        $report->setData(['columns' => $built['columns'], 'rows' => $built['rows']]);
        $report->setGeneratedBy($user->getId(), $user->getFirstName() . ' ' . $user->getLastName());
        $this->em->persist($report);
        $this->em->flush();
        $this->audit->log('report.generate', $user, 'Report', (string) $report->getId(), null, ['type' => $type]);

        return Json::write($response, $report->toArray(true), 201);
    }

    /** GET /platform/reports/{id} — the full snapshot incl. rows. */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $report = $this->em->getRepository(Report::class)->find((int) $args['id']);
        if ($report === null) {
            return Json::error($response, 'Report not found.', 404);
        }
        return Json::write($response, $report->toArray(true));
    }

    /** GET /platform/reports/{id}/export — download the snapshot as CSV. */
    public function export(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $report = $this->em->getRepository(Report::class)->find((int) $args['id']);
        if ($report === null) {
            return Json::error($response, 'Report not found.', 404);
        }
        $data = $report->toArray(true)['data'] ?? ['columns' => [], 'rows' => []];

        $fh = fopen('php://temp', 'r+');
        if (!empty($data['columns'])) {
            fputcsv($fh, $data['columns']);
        }
        foreach ($data['rows'] ?? [] as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $slug = $report->getType() . '-' . $report->getId();
        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $slug . '.csv"');
    }

    /** DELETE /platform/reports/{id} — remove a snapshot from the history. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $report = $this->em->getRepository(Report::class)->find((int) $args['id']);
        if ($report === null) {
            return Json::error($response, 'Report not found.', 404);
        }
        $id = $report->getId();
        $this->em->remove($report);
        $this->em->flush();
        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    // --- report builders (all live, real data) ---

    /** @return array{summary: array<int, array{label:string, value:mixed}>, columns: array<int,string>, rows: array<int, array<int, mixed>>} */
    private function buildPlatformOverview(): array
    {
        $graded = $this->em->createQueryBuilder()->select('COUNT(at.id) AS c', 'AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)
            ->getQuery()->getSingleResult();
        $avg = $graded['avg'] === null ? null : round((float) $graded['avg'], 1);
        $mrr = $this->mrrNaira();

        $metrics = [
            ['Institutions', (int) $this->em->getRepository(Institution::class)->count([])],
            ['Active institutions', (int) $this->em->getRepository(Institution::class)->count(['status' => 'active'])],
            ['Total users', (int) $this->em->getRepository(User::class)->count([])],
            ['Learners', $this->countRole('student')],
            ['Teachers', $this->countRole('teacher')],
            ['Subjects', (int) $this->em->getRepository(\App\Domain\Entity\Subject::class)->count([])],
            ['Published assessments', (int) $this->em->getRepository(Assessment::class)->count(['approvalStatus' => Lifecycle::PUBLISHED])],
            ['Graded attempts', (int) $graded['c']],
            ['Average score (%)', $avg ?? '—'],
            ['Monthly revenue (₦)', $mrr],
        ];

        return [
            'summary' => [
                ['label' => 'Institutions', 'value' => (int) $this->em->getRepository(Institution::class)->count([])],
                ['label' => 'Users', 'value' => (int) $this->em->getRepository(User::class)->count([])],
                ['label' => 'Avg score', 'value' => $avg === null ? '—' : $avg . '%'],
                ['label' => 'MRR', 'value' => '₦' . number_format($mrr)],
            ],
            'columns' => ['Metric', 'Value'],
            'rows' => $metrics,
        ];
    }

    /** @return array{summary: array<int, array{label:string, value:mixed}>, columns: array<int,string>, rows: array<int, array<int, mixed>>} */
    private function buildInstitutionPerformance(): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Institution::class)->findAll() as $inst) {
            $rows[$inst->getId()] = [$inst->getName(), $inst->getStatus(), 0, 0, 0, '—'];
        }
        foreach ($this->groupCount(User::class, 'u', 'u.institution', 'u.institution IS NOT NULL') as $r) {
            if (isset($rows[(int) $r['k']])) {
                $rows[(int) $r['k']][2] = (int) $r['c'];
            }
        }
        $students = $this->em->createQueryBuilder()->select('IDENTITY(u.institution) AS k', 'COUNT(u.id) AS c')
            ->from(User::class, 'u')->join('u.role', 'r')->where('u.institution IS NOT NULL')->andWhere('r.code = :s')
            ->setParameter('s', 'student')->groupBy('u.institution')->getQuery()->getArrayResult();
        foreach ($students as $r) {
            if (isset($rows[(int) $r['k']])) {
                $rows[(int) $r['k']][3] = (int) $r['c'];
            }
        }
        $attempts = $this->em->createQueryBuilder()->select('IDENTITY(s.institution) AS k', 'COUNT(at.id) AS c', 'AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')->join('at.assessment', 'a')->join('a.subject', 's')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)->groupBy('s.institution')->getQuery()->getArrayResult();
        foreach ($attempts as $r) {
            if (isset($rows[(int) $r['k']])) {
                $rows[(int) $r['k']][4] = (int) $r['c'];
                $rows[(int) $r['k']][5] = $r['avg'] === null ? '—' : round((float) $r['avg'], 1);
            }
        }
        $out = array_values($rows);
        usort($out, static fn ($a, $b) => $b[4] <=> $a[4]);

        $totalAttempts = array_sum(array_map(static fn ($r) => $r[4], $out));
        return [
            'summary' => [
                ['label' => 'Institutions', 'value' => count($out)],
                ['label' => 'Total attempts', 'value' => $totalAttempts],
                ['label' => 'Active', 'value' => (int) $this->em->getRepository(Institution::class)->count(['status' => 'active'])],
            ],
            'columns' => ['Institution', 'Status', 'Users', 'Learners', 'Attempts', 'Avg score (%)'],
            'rows' => $out,
        ];
    }

    /** @return array{summary: array<int, array{label:string, value:mixed}>, columns: array<int,string>, rows: array<int, array<int, mixed>>} */
    private function buildSubscriptions(): array
    {
        $rows = [];
        $active = 0;
        $mrr = 0;
        foreach ($this->em->getRepository(SubscriptionPlan::class)->findBy(['isActive' => true]) as $plan) {
            /** @var SubscriptionPlan $plan */
            $subs = $this->em->getRepository(Subscription::class)->findBy(['plan' => $plan]);
            $activeForPlan = array_filter($subs, static fn (Subscription $s) => in_array($s->status(), [Subscription::ACTIVE, Subscription::GRACE], true));
            $price = $plan->getPriceKobo() / 100;
            $planMrr = count($activeForPlan) * $price;
            $active += count($activeForPlan);
            $mrr += $planMrr;
            $rows[] = [$plan->getName(), count($subs), count($activeForPlan), number_format($price), number_format($planMrr)];
        }

        return [
            'summary' => [
                ['label' => 'Active subscriptions', 'value' => $active],
                ['label' => 'MRR', 'value' => '₦' . number_format($mrr)],
                ['label' => 'ARR', 'value' => '₦' . number_format($mrr * 12)],
            ],
            'columns' => ['Plan', 'Total subscribers', 'Active', 'Price (₦)', 'MRR (₦)'],
            'rows' => $rows,
        ];
    }

    /** @return array{summary: array<int, array{label:string, value:mixed}>, columns: array<int,string>, rows: array<int, array<int, mixed>>} */
    private function buildUserGrowth(): array
    {
        $now = new DateTimeImmutable();
        $months = [];
        $order = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = $now->modify("first day of -$i month")->format('Y-m');
            $order[] = $key;
            $months[$key] = ['users' => 0, 'institutions' => 0, 'attempts' => 0];
        }
        $bucket = static function (array &$months, $dt, string $field): void {
            if ($dt === null) {
                return;
            }
            $key = $dt->format('Y-m');
            if (isset($months[$key])) {
                $months[$key][$field]++;
            }
        };
        foreach ($this->em->createQueryBuilder()->select('u.createdAt AS d')->from(User::class, 'u')->getQuery()->getArrayResult() as $r) {
            $bucket($months, $r['d'], 'users');
        }
        foreach ($this->em->createQueryBuilder()->select('i.createdAt AS d')->from(Institution::class, 'i')->getQuery()->getArrayResult() as $r) {
            $bucket($months, $r['d'], 'institutions');
        }
        $attempts = $this->em->createQueryBuilder()->select('at.submittedAt AS d')->from(AssessmentAttempt::class, 'at')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)->getQuery()->getArrayResult();
        foreach ($attempts as $r) {
            $bucket($months, $r['d'], 'attempts');
        }

        $rows = array_map(static fn (string $k) => [$k, $months[$k]['users'], $months[$k]['institutions'], $months[$k]['attempts']], $order);

        return [
            'summary' => [
                ['label' => 'Total users', 'value' => (int) $this->em->getRepository(User::class)->count([])],
                ['label' => 'Institutions', 'value' => (int) $this->em->getRepository(Institution::class)->count([])],
                ['label' => 'Window', 'value' => 'Last 6 months'],
            ],
            'columns' => ['Month', 'New users', 'New institutions', 'Assessments taken'],
            'rows' => $rows,
        ];
    }

    // --- helpers ---

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform owner can manage reports.', 403);
        }
        return null;
    }

    private function countRole(string $role): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :role')->setParameter('role', $role)->getQuery()->getSingleScalarResult();
    }

    private function mrrNaira(): float
    {
        $mrr = 0;
        foreach ($this->em->getRepository(Subscription::class)->findAll() as $s) {
            /** @var Subscription $s */
            if (in_array($s->status(), [Subscription::ACTIVE, Subscription::GRACE], true)) {
                $mrr += $s->getPlan()->getPriceKobo();
            }
        }
        return $mrr / 100;
    }

    /**
     * COUNT grouped by an association path, returned as [{k, c}].
     *
     * @return array<int, array{k:mixed, c:mixed}>
     */
    private function groupCount(string $entity, string $alias, string $assocPath, string $where): array
    {
        return $this->em->createQueryBuilder()->select("IDENTITY($assocPath) AS k", "COUNT($alias.id) AS c")
            ->from($entity, $alias)->where($where)->groupBy($assocPath)->getQuery()->getArrayResult();
    }
}
