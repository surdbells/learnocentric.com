<?php

declare(strict_types=1);

namespace App\Application\Actions\Teacher;

use App\Application\Support\Json;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\User;
use App\Domain\Entity\WorksheetSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/teacher/classes — the "My Classes" workspace for a teacher:
 * per-class roster stats (learners, graded-attempt average, last activity),
 * a performance distribution across their learners, today's live schedule,
 * and headline KPIs. All figures come from real data (assignments,
 * enrolments, graded attempts, live classes); classes with no graded
 * attempts report a null average rather than a fabricated one.
 */
final class TeacherClassesAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $me */
        $me = $request->getAttribute('user');

        $assignments = $this->em->getRepository(TeacherAssignment::class)->findBy(['teacher' => $me]);

        // Group the teacher's assignments by class, collecting the subjects taught there.
        $classes = [];        // cid => ['class' => SchoolClass, 'subjects' => [name => true]]
        $subjectIds = [];
        foreach ($assignments as $a) {
            $class = $a->getSchoolClass();
            $cid = $class->getId();
            $classes[$cid] ??= ['class' => $class, 'subjects' => []];
            $classes[$cid]['subjects'][$a->getSubject()->getName()] = true;
            $subjectIds[$a->getSubject()->getId()] = true;
        }

        $rows = [];
        $allPercentages = [];
        $totalLearners = 0;
        foreach ($classes as $cid => $entry) {
            $class = $entry['class'];
            $learners = (int) $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $cid]);
            $totalLearners += $learners;

            // Graded-attempt average + count for enrolled learners in this class.
            $stat = $this->em->createQueryBuilder()
                ->select('AVG(at.percentage) AS avg', 'COUNT(at.id) AS c', 'MAX(at.submittedAt) AS last')
                ->from(AssessmentAttempt::class, 'at')->join('at.student', 'st')
                ->join(Enrollment::class, 'e', Join::WITH, 'e.student = st')
                ->where('e.schoolClass = :cid')->andWhere('at.status = :g')
                ->setParameter('cid', $cid)->setParameter('g', AssessmentAttempt::GRADED)
                ->getQuery()->getSingleResult();

            // Collect percentages for the overall distribution.
            $pcts = $this->em->createQueryBuilder()->select('at.percentage AS p')
                ->from(AssessmentAttempt::class, 'at')->join('at.student', 'st')
                ->join(Enrollment::class, 'e', Join::WITH, 'e.student = st')
                ->where('e.schoolClass = :cid')->andWhere('at.status = :g')
                ->setParameter('cid', $cid)->setParameter('g', AssessmentAttempt::GRADED)
                ->getQuery()->getScalarResult();
            foreach ($pcts as $p) {
                $allPercentages[] = (float) $p['p'];
            }

            $lastLive = $this->em->createQueryBuilder()->select('MAX(lc.scheduledAt) AS last')
                ->from(LiveClass::class, 'lc')->where('lc.schoolClass = :cid')
                ->setParameter('cid', $cid)->getQuery()->getSingleScalarResult();

            $lastActivity = $this->maxDate([$stat['last'] ?? null, $lastLive]);

            $rows[] = [
                'id' => $cid,
                'label' => $class->getLabel(),
                'subject' => implode(', ', array_keys($entry['subjects'])),
                'learners' => $learners,
                'average' => $stat['avg'] === null ? null : round((float) $stat['avg'], 1),
                'attempts' => (int) $stat['c'],
                'last_activity' => $lastActivity,
                'status' => 'active',
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp((string) $b['last_activity'], (string) $a['last_activity']));

        $schedule = $this->todaySchedule($me);

        return Json::write($response, [
            'kpis' => [
                'total_classes' => count($rows),
                'total_learners' => $totalLearners,
                'class_average' => $allPercentages === [] ? null : round(array_sum($allPercentages) / count($allPercentages), 1),
                'classes_today' => count($schedule),
                'pending_reviews' => $this->pendingReviewCount(array_keys($subjectIds)),
            ],
            'classes' => $rows,
            'performance_distribution' => $this->distribution($allPercentages),
            'today_schedule' => $schedule,
        ]);
    }

    /** @param float[] $percentages */
    private function distribution(array $percentages): array
    {
        $bands = [
            ['band' => 'Excellent (80–100%)', 'tone' => 'success', 'min' => 80, 'count' => 0],
            ['band' => 'Very Good (70–79%)', 'tone' => 'primary', 'min' => 70, 'count' => 0],
            ['band' => 'Good (60–69%)', 'tone' => 'warning', 'min' => 60, 'count' => 0],
            ['band' => 'Average (50–59%)', 'tone' => 'info', 'min' => 50, 'count' => 0],
            ['band' => 'Below Average (<50%)', 'tone' => 'danger', 'min' => 0, 'count' => 0],
        ];
        foreach ($percentages as $p) {
            foreach ($bands as $i => $b) {
                if ($p >= $b['min']) { $bands[$i]['count']++; break; }
            }
        }
        $total = max(1, count($percentages));
        return array_map(static function ($b) use ($total) {
            return ['band' => $b['band'], 'tone' => $b['tone'], 'count' => $b['count'], 'pct' => (int) round($b['count'] / $total * 100)];
        }, $bands);
    }

    /** Live classes the teacher hosts today, ordered by time. */
    private function todaySchedule(User $me): array
    {
        $start = new \DateTimeImmutable('today 00:00:00');
        $end = $start->modify('+1 day');
        $rows = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')
            ->where('lc.host = :me')->andWhere('lc.scheduledAt >= :s')->andWhere('lc.scheduledAt < :e')
            ->setParameter('me', $me)->setParameter('s', $start)->setParameter('e', $end)
            ->orderBy('lc.scheduledAt', 'ASC')->getQuery()->getResult();
        return array_map(static function (LiveClass $lc) {
            return [
                'id' => $lc->getId(),
                'title' => $lc->getTitle(),
                'class' => $lc->getSchoolClass()?->getLabel(),
                'subject' => $lc->getSubject()->getName(),
                'topic' => $lc->getTopic()?->getTitle(),
                'scheduled_at' => $lc->getScheduledAt()->format(DATE_ATOM),
                'duration_minutes' => $lc->getDurationMinutes(),
                'status' => $lc->getStatus(),
            ];
        }, $rows);
    }

    /** @param int[] $subjectIds */
    private function pendingReviewCount(array $subjectIds): int
    {
        if ($subjectIds === []) {
            return 0;
        }
        $ws = (int) $this->em->createQueryBuilder()->select('COUNT(ws.id)')->from(WorksheetSubmission::class, 'ws')
            ->join('ws.worksheet', 'w')->join('w.topic', 't')
            ->where('ws.status = :st')->andWhere('t.subject IN (:subs)')
            ->setParameter('st', WorksheetSubmission::SUBMITTED)->setParameter('subs', $subjectIds)
            ->getQuery()->getSingleScalarResult();
        $pf = (int) $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(PortfolioEntry::class, 'p')
            ->join('p.topic', 't')
            ->where('p.status = :st')->andWhere('t.subject IN (:subs)')
            ->setParameter('st', PortfolioEntry::SUBMITTED)->setParameter('subs', $subjectIds)
            ->getQuery()->getSingleScalarResult();
        return $ws + $pf;
    }

    /** @param array<int, mixed> $dates */
    private function maxDate(array $dates): ?string
    {
        $best = null;
        foreach ($dates as $d) {
            if ($d === null) {
                continue;
            }
            $iso = $d instanceof \DateTimeInterface ? $d->format(DATE_ATOM) : (string) $d;
            if ($best === null || strcmp($iso, $best) > 0) {
                $best = $iso;
            }
        }
        return $best;
    }
}
