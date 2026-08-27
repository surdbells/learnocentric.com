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
 * Platform-wide analytics for the super admin, the first cross-institution
 * aggregation surface (everything else is school-scoped). All figures are real
 * counts/averages over the current data; nothing is synthesised.
 */
final class PlatformAnalyticsAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /platform/analytics, headline totals, growth + activity trends, and an engagement leaderboard. */
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
            'deltas' => $this->deltas($now),
            'roles' => $this->roleBreakdown(),
            'content_readiness' => $this->contentReadiness(),
            'growth' => $this->growth($now),
            'activity' => $this->dailyActiveUsers($now, 21),
            'completion_trend' => $this->completionTrend($now),
            'content_by_subject' => $this->contentBySubject(),
            'heatmap' => $this->heatmap($now, 4),
            'recent_activity' => $this->recentActivity(),
            'institutions' => $this->leaderboard(),
            'plans' => $this->planBreakdown(),
            'generated_at' => $now->format(DATE_ATOM),
        ]);
    }

    /**
     * Period-over-period deltas (last 30 days vs the 30 before) for the headline
     * figures, so KPI cards can show a real trend arrow. Percentages are signed.
     *
     * @return array<string, float>
     */
    private function deltas(DateTimeImmutable $now): array
    {
        $mid = $now->modify('-30 days');
        $start = $now->modify('-60 days');
        $pct = static fn (int $cur, int $prev): float => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? 100.0 : 0.0);

        $countCreated = function (string $entity, string $field, DateTimeImmutable $from, DateTimeImmutable $to): int {
            return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from($entity, 'e')
                ->where("e.$field >= :from")->andWhere("e.$field < :to")
                ->setParameter('from', $from)->setParameter('to', $to)->getQuery()->getSingleScalarResult();
        };

        $roleCreated = function (string $role, DateTimeImmutable $from, DateTimeImmutable $to): int {
            return (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
                ->where('r.code = :role')->andWhere('u.createdAt >= :from')->andWhere('u.createdAt < :to')
                ->setParameter('role', $role)->setParameter('from', $from)->setParameter('to', $to)->getQuery()->getSingleScalarResult();
        };

        return [
            'users' => $pct($countCreated(User::class, 'createdAt', $mid, $now), $countCreated(User::class, 'createdAt', $start, $mid)),
            'institutions' => $pct($countCreated(Institution::class, 'createdAt', $mid, $now), $countCreated(Institution::class, 'createdAt', $start, $mid)),
            'students' => $pct($roleCreated('student', $mid, $now), $roleCreated('student', $start, $mid)),
            'teachers' => $pct($roleCreated('teacher', $mid, $now), $roleCreated('teacher', $start, $mid)),
            'attempts' => $pct($this->gradedBetween($mid, $now), $this->gradedBetween($start, $mid)),
        ];
    }

    private function gradedBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(at.id)')->from(AssessmentAttempt::class, 'at')
            ->where('at.status = :g')->andWhere('at.submittedAt >= :from')->andWhere('at.submittedAt < :to')
            ->setParameter('g', AssessmentAttempt::GRADED)->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Completed vs in-progress attempts per month (last 6) for a stacked trend.
     *
     * @return array<int, array{month:string, completed:int, in_progress:int}>
     */
    private function completionTrend(DateTimeImmutable $now): array
    {
        $months = [];
        $order = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = $now->modify("first day of -$i month")->format('Y-m');
            $order[] = $key;
            $months[$key] = ['month' => $key, 'completed' => 0, 'in_progress' => 0];
        }
        $rows = $this->em->createQueryBuilder()->select('at.status AS st', 'at.submittedAt AS sub', 'at.startedAt AS started')
            ->from(AssessmentAttempt::class, 'at')->getQuery()->getArrayResult();
        foreach ($rows as $r) {
            $when = $r['sub'] ?? $r['started'];
            if ($when === null) {
                continue;
            }
            $key = $when->format('Y-m');
            if (!isset($months[$key])) {
                continue;
            }
            if ($r['st'] === AssessmentAttempt::GRADED) {
                $months[$key]['completed']++;
            } else {
                $months[$key]['in_progress']++;
            }
        }
        return array_map(static fn (string $k) => $months[$k], $order);
    }

    /**
     * Graded attempts grouped by subject, content consumption signal.
     *
     * @return array<int, array{subject:string, count:int}>
     */
    private function contentBySubject(): array
    {
        $rows = $this->em->createQueryBuilder()->select('s.name AS subject', 'COUNT(at.id) AS c')
            ->from(AssessmentAttempt::class, 'at')->join('at.assessment', 'a')->join('a.subject', 's')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)
            ->groupBy('s.name')->orderBy('c', 'DESC')->getQuery()->getArrayResult();
        return array_map(static fn (array $r) => ['subject' => (string) $r['subject'], 'count' => (int) $r['c']], $rows);
    }

    /**
     * Distinct active users per weekday across the last $weeks weeks (from the
     * audit trail), a week × weekday engagement heatmap.
     *
     * @return array<int, array{label:string, values:int[]}>
     */
    private function heatmap(DateTimeImmutable $now, int $weeks): array
    {
        // Monday-anchored week start for the earliest week in the window.
        $todayDow = (int) $now->format('N'); // 1=Mon..7=Sun
        $thisMonday = $now->modify('-' . ($todayDow - 1) . ' days')->setTime(0, 0);
        $start = $thisMonday->modify('-' . ($weeks - 1) . ' weeks');

        // seen[weekIndex][weekday] = set of userIds
        $seen = [];
        for ($w = 0; $w < $weeks; $w++) {
            $seen[$w] = array_fill(0, 7, []);
        }
        $rows = $this->em->createQueryBuilder()->select('a.userId AS uid', 'a.createdAt AS d')
            ->from(AuditLog::class, 'a')->where('a.createdAt >= :start')->andWhere('a.userId IS NOT NULL')
            ->setParameter('start', $start)->getQuery()->getArrayResult();
        foreach ($rows as $r) {
            /** @var DateTimeImmutable $d */
            $d = $r['d'];
            $weekIdx = (int) floor(($d->getTimestamp() - $start->getTimestamp()) / (7 * 86400));
            if ($weekIdx < 0 || $weekIdx >= $weeks) {
                continue;
            }
            $dow = (int) $d->format('N') - 1;
            $seen[$weekIdx][$dow][(int) $r['uid']] = true;
        }

        $out = [];
        for ($w = 0; $w < $weeks; $w++) {
            $label = $start->modify("+$w weeks")->format('M j');
            $out[] = ['label' => $label, 'values' => array_map(static fn (array $s) => count($s), $seen[$w])];
        }
        return $out;
    }

    /**
     * The most recent platform actions, humanised for a feed.
     *
     * @return array<int, array{action:string, object:string|null, when:string}>
     */
    private function recentActivity(): array
    {
        $labels = [
            'attempt.submit' => 'Assessment submitted',
            'attempt.start' => 'Assessment started',
            'report.generate' => 'Report generated',
            'portfolio.review' => 'Portfolio reviewed',
            'portfolio.submit' => 'Portfolio evidence submitted',
            'safeguarding.report' => 'Safeguarding concern reported',
            'safeguarding.platform_update' => 'Safeguarding case triaged',
            'institution.settings' => 'School settings updated',
            'platform.settings' => 'Platform settings updated',
            'deliverypack.create' => 'Delivery pack created',
        ];
        $rows = $this->em->createQueryBuilder()->select('a.actionType AS act', 'a.objectType AS obj', 'a.createdAt AS d')
            ->from(AuditLog::class, 'a')->orderBy('a.createdAt', 'DESC')->setMaxResults(8)->getQuery()->getArrayResult();
        return array_map(static function (array $r) use ($labels) {
            return [
                'action' => $labels[$r['act']] ?? ucfirst(str_replace(['.', '_'], ' ', (string) $r['act'])),
                'object' => $r['obj'],
                'when' => $r['d']->format(DATE_ATOM),
            ];
        }, $rows);
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

    /**
     * Delivery-pack readiness across the platform: published (approved assets),
     * in-review (pending), and drafts (missing/incomplete).
     *
     * @return array<string, int>
     */
    private function contentReadiness(): array
    {
        $rows = $this->em->createQueryBuilder()->select('p.status AS st', 'COUNT(p.id) AS c')
            ->from(\App\Domain\Entity\TopicDeliveryPack::class, 'p')->groupBy('p.status')->getQuery()->getArrayResult();
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r['st']] = (int) $r['c'];
        }
        return [
            'published' => $by[Lifecycle::PUBLISHED] ?? 0,
            'pending' => ($by[Lifecycle::REVIEW] ?? 0) + ($by[Lifecycle::APPROVED] ?? 0),
            'draft' => $by[Lifecycle::DRAFT] ?? 0,
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
