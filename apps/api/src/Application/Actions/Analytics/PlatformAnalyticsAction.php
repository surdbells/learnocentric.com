<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\AuditLog;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platform-wide analytics for the super admin — the first cross-institution
 * aggregation surface (everything else is school-scoped). All figures are real
 * counts/averages over the current data; nothing is synthesised.
 */
final class PlatformAnalyticsAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /platform/analytics — headline totals, growth + activity trends, and an engagement leaderboard. */
    public function overview(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform owner can view platform analytics.', 403);
        }

        $now = new DateTimeImmutable();

        return Json::write($response, [
            'totals' => $this->totals(),
            'roles' => $this->roleBreakdown(),
            'growth' => $this->growth($now),
            'activity' => $this->dailyActiveUsers($now, 14),
            'institutions' => $this->leaderboard(),
            'plans' => $this->planBreakdown(),
            'generated_at' => $now->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, int|float|null> */
    private function totals(): array
    {
        $subs = $this->em->getRepository(Subscription::class)->findAll();
        $active = array_filter($subs, static fn (Subscription $s) => in_array($s->status(), [Subscription::ACTIVE, Subscription::GRACE], true));
        $mrr = 0;
        foreach ($active as $s) {
            $mrr += $s->getPlan()->getPriceKobo();
        }

        $graded = $this->em->createQueryBuilder()->select('COUNT(at.id) AS c', 'AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)
            ->getQuery()->getSingleResult();

        return [
            'institutions' => (int) $this->em->getRepository(Institution::class)->count([]),
            'active_institutions' => (int) $this->em->getRepository(Institution::class)->count(['status' => 'active']),
            'users' => (int) $this->em->getRepository(User::class)->count([]),
            'students' => $this->countRole('student'),
            'teachers' => $this->countRole('teacher'),
            'subjects' => (int) $this->em->getRepository(Subject::class)->count([]),
            'assessments' => (int) $this->em->getRepository(Assessment::class)->count([]),
            'published_assessments' => (int) $this->em->getRepository(Assessment::class)->count(['approvalStatus' => Lifecycle::PUBLISHED]),
            'graded_attempts' => (int) $graded['c'],
            'avg_score' => $graded['avg'] === null ? null : round((float) $graded['avg'], 1),
            'active_subscriptions' => count($active),
            'mrr_naira' => $mrr / 100,
            'arr_naira' => $mrr * 12 / 100,
        ];
    }

    /** @return array<int, array{role:string, count:int}> */
    private function roleBreakdown(): array
    {
        $rows = $this->em->createQueryBuilder()->select('r.code AS role', 'COUNT(u.id) AS c')
            ->from(User::class, 'u')->join('u.role', 'r')->groupBy('r.code')->orderBy('c', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => ['role' => (string) $r['role'], 'count' => (int) $r['c']], $rows);
    }

    /**
     * Institutions / users / graded-attempts created in each of the last 6 months.
     *
     * @return array<int, array{month:string, institutions:int, users:int, attempts:int}>
     */
    private function growth(DateTimeImmutable $now): array
    {
        $months = [];
        $order = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = $now->modify("first day of -$i month")->format('Y-m');
            $order[] = $key;
            $months[$key] = ['month' => $key, 'institutions' => 0, 'users' => 0, 'attempts' => 0];
        }
        $bucket = static function (array &$months, ?DateTimeImmutable $dt, string $field): void {
            if ($dt === null) {
                return;
            }
            $key = $dt->format('Y-m');
            if (isset($months[$key])) {
                $months[$key][$field]++;
            }
        };

        foreach ($this->em->createQueryBuilder()->select('i.createdAt AS d')->from(Institution::class, 'i')->getQuery()->getArrayResult() as $r) {
            $bucket($months, $r['d'], 'institutions');
        }
        foreach ($this->em->createQueryBuilder()->select('u.createdAt AS d')->from(User::class, 'u')->getQuery()->getArrayResult() as $r) {
            $bucket($months, $r['d'], 'users');
        }
        $attempts = $this->em->createQueryBuilder()->select('at.submittedAt AS d')->from(AssessmentAttempt::class, 'at')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)->getQuery()->getArrayResult();
        foreach ($attempts as $r) {
            $bucket($months, $r['d'], 'attempts');
        }

        return array_map(static fn (string $k) => $months[$k], $order);
    }

    /**
     * Distinct users acting per day over the last $days days, from the audit trail.
     *
     * @return array<int, array{day:string, active:int}>
     */
    private function dailyActiveUsers(DateTimeImmutable $now, int $days): array
    {
        $since = $now->modify('-' . ($days - 1) . ' days')->setTime(0, 0);
        $seen = [];
        $order = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = $now->modify("-$i days")->format('Y-m-d');
            $order[] = $key;
            $seen[$key] = [];
        }

        $rows = $this->em->createQueryBuilder()->select('a.userId AS uid', 'a.createdAt AS d')
            ->from(AuditLog::class, 'a')->where('a.createdAt >= :since')->andWhere('a.userId IS NOT NULL')
            ->setParameter('since', $since)->getQuery()->getArrayResult();
        foreach ($rows as $r) {
            $key = $r['d']->format('Y-m-d');
            if (isset($seen[$key])) {
                $seen[$key][(int) $r['uid']] = true;
            }
        }

        return array_map(static fn (string $k) => ['day' => $k, 'active' => count($seen[$k])], $order);
    }

    /**
     * Per-institution engagement: users, students and graded attempts + average
     * score, so healthy and at-risk (zero-activity) schools both surface.
     *
     * @return array<int, array<string, mixed>>
     */
    private function leaderboard(): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Institution::class)->findAll() as $inst) {
            /** @var Institution $inst */
            $rows[$inst->getId()] = [
                'id' => $inst->getId(),
                'name' => $inst->getName(),
                'status' => $inst->getStatus(),
                'type' => $inst->getType(),
                'users' => 0,
                'students' => 0,
                'attempts' => 0,
                'avg_score' => null,
            ];
        }

        $users = $this->em->createQueryBuilder()->select('IDENTITY(u.institution) AS inst', 'COUNT(u.id) AS c')
            ->from(User::class, 'u')->where('u.institution IS NOT NULL')->groupBy('u.institution')->getQuery()->getArrayResult();
        foreach ($users as $r) {
            if (isset($rows[(int) $r['inst']])) {
                $rows[(int) $r['inst']]['users'] = (int) $r['c'];
            }
        }

        $students = $this->em->createQueryBuilder()->select('IDENTITY(u.institution) AS inst', 'COUNT(u.id) AS c')
            ->from(User::class, 'u')->join('u.role', 'r')->where('u.institution IS NOT NULL')->andWhere('r.code = :s')
            ->setParameter('s', 'student')->groupBy('u.institution')->getQuery()->getArrayResult();
        foreach ($students as $r) {
            if (isset($rows[(int) $r['inst']])) {
                $rows[(int) $r['inst']]['students'] = (int) $r['c'];
            }
        }

        $attempts = $this->em->createQueryBuilder()->select('IDENTITY(s.institution) AS inst', 'COUNT(at.id) AS c', 'AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')->join('at.assessment', 'a')->join('a.subject', 's')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)->groupBy('s.institution')->getQuery()->getArrayResult();
        foreach ($attempts as $r) {
            if (isset($rows[(int) $r['inst']])) {
                $rows[(int) $r['inst']]['attempts'] = (int) $r['c'];
                $rows[(int) $r['inst']]['avg_score'] = $r['avg'] === null ? null : round((float) $r['avg'], 1);
            }
        }

        $out = array_values($rows);
        usort($out, static fn ($a, $b) => $b['attempts'] <=> $a['attempts'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array<int, array{name:string, subscribers:int}> */
    private function planBreakdown(): array
    {
        return array_map(fn (SubscriptionPlan $p) => [
            'name' => $p->getName(),
            'subscribers' => (int) $this->em->getRepository(Subscription::class)->count(['plan' => $p]),
        ], $this->em->getRepository(SubscriptionPlan::class)->findBy(['isActive' => true]));
    }

    private function countRole(string $role): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :role')->setParameter('role', $role)->getQuery()->getSingleScalarResult();
    }
}
