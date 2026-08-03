<?php

declare(strict_types=1);

namespace App\Application\Actions\Dashboard;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\FeedbackNote;
use App\Domain\Entity\GuardianLink;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Intervention;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\Notification;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\SafeguardingCase;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\Topic;
use App\Domain\Entity\TopicDeliveryPack;
use App\Domain\Entity\TopicProgress;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Entity\WorksheetSubmission;
use App\Domain\Lifecycle;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Compact, real dashboard payloads per role. */
final class DashboardAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /dashboard/admin — institution overview + action items. */
    public function admin(Request $request, Response $response): Response
    {
        $inst = $this->resolveInstitution($request, $this->em);

        $attempts = $this->gradedAttempts($inst);
        $quizAvg = $this->avg(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $attempts));
        $passRate = $attempts ? round(count(array_filter($attempts, static fn (AssessmentAttempt $a) => $a->isPassed())) / count($attempts) * 100, 1) : null;

        return Json::write($response, [
            'stats' => [
                'students' => $this->countRole('student', $inst),
                'teachers' => $this->countRole('teacher', $inst),
                'subjects' => $this->countScoped(Subject::class, 's', $inst),
                'classes' => $this->countScoped(SchoolClass::class, 'sc', $inst, 'sc.institution'),
                'published_topics' => $this->countPublished(Topic::class, 't', $inst),
                'live_classes' => $this->countScoped(LiveClass::class, 'lc', $inst, null, 'lc.subject'),
            ],
            'action_items' => [
                'worksheets_to_grade' => $this->countSubmissions($inst, WorksheetSubmission::SUBMITTED),
                'portfolio_to_review' => $this->countPortfolio($inst, PortfolioEntry::SUBMITTED),
                'interventions_open' => $this->countInterventions($inst, [Intervention::OPEN, Intervention::IN_PROGRESS]),
                'safeguarding_open' => $this->countSafeguarding($inst),
            ],
            'quiz' => ['average' => $quizAvg, 'pass_rate' => $passRate, 'attempts' => count($attempts)],
            'quiz_by_subject' => $this->quizBySubject($attempts),
            'curriculum_coverage' => $this->curriculumCoverage($inst),
            'teacher_activity' => $this->teacherActivity($inst),
        ]);
    }

    /**
     * Curriculum coverage split for the coverage donut: a published topic is
     * On track when it has a published delivery pack, Behind when a pack exists
     * but isn't published yet, and At risk when it has no pack at all.
     *
     * @return array{on_track:int, behind:int, at_risk:int, total:int, coverage_pct:int}
     */
    private function curriculumCoverage(?Institution $inst): array
    {
        $qb = $this->em->createQueryBuilder()->select('t.id AS tid', 'p.status AS pstatus')
            ->from(Topic::class, 't')->join('t.subject', 's')
            ->leftJoin(TopicDeliveryPack::class, 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 'p.topic = t')
            ->where('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        $onTrack = $behind = $atRisk = 0;
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            if ($r['pstatus'] === Lifecycle::PUBLISHED) {
                $onTrack++;
            } elseif ($r['pstatus'] === null) {
                $atRisk++;
            } else {
                $behind++;
            }
        }
        $total = $onTrack + $behind + $atRisk;
        return [
            'on_track' => $onTrack, 'behind' => $behind, 'at_risk' => $atRisk, 'total' => $total,
            'coverage_pct' => $total > 0 ? (int) round($onTrack / $total * 100) : 0,
        ];
    }

    /**
     * Teacher delivery activity counts for the activity panel — all real totals
     * over the institution.
     *
     * @return array<string, int>
     */
    private function teacherActivity(?Institution $inst): array
    {
        return [
            'delivery_packs' => $this->countDeliveryPacks($inst),
            'live_classes' => $this->countScoped(LiveClass::class, 'lc', $inst, null, 'lc.subject'),
            'assessments' => $this->countPublished(Assessment::class, 'a', $inst),
            'feedback_given' => $this->countFeedback($inst),
            'worksheets_graded' => $this->countSubmissions($inst, WorksheetSubmission::GRADED),
        ];
    }

    private function countDeliveryPacks(?Institution $inst): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(TopicDeliveryPack::class, 'p')
            ->join('p.topic', 't')->join('t.subject', 's')
            ->where('p.status = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countFeedback(?Institution $inst): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(f.id)')->from(FeedbackNote::class, 'f')->join('f.student', 'st');
        if ($inst !== null) {
            $qb->andWhere('st.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** GET /dashboard/teacher — my classes + things needing my attention. */
    public function teacher(Request $request, Response $response): Response
    {
        /** @var User $me */
        $me = $request->getAttribute('user');
        $inst = $me->getInstitution();

        $assignments = $this->em->getRepository(TeacherAssignment::class)->findBy(['teacher' => $me]);
        $classIds = [];
        $subjectIds = [];
        foreach ($assignments as $a) {
            $classIds[$a->getSchoolClass()->getId()] = true;
            $subjectIds[$a->getSubject()->getId()] = true;
        }
        $students = 0;
        foreach (array_keys($classIds) as $cid) {
            $students += (int) $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $cid]);
        }

        return Json::write($response, [
            'stats' => [
                'my_classes' => count($classIds),
                'my_subjects' => count($subjectIds),
                'my_students' => $students,
                'upcoming_live' => $this->myUpcomingLive($me),
            ],
            'action_items' => [
                'worksheets_to_grade' => $this->countSubmissions($inst, WorksheetSubmission::SUBMITTED),
                'portfolio_to_review' => $this->countPortfolio($inst, PortfolioEntry::SUBMITTED),
                'my_interventions' => (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Intervention::class, 'i')
                    ->where('i.assignedTo = :me')->andWhere('i.status IN (:open)')
                    ->setParameter('me', $me)->setParameter('open', [Intervention::OPEN, Intervention::IN_PROGRESS])
                    ->getQuery()->getSingleScalarResult(),
            ],
            'upcoming' => $this->upcomingLiveList($me),
        ]);
    }

    /** GET /dashboard/student — my progress + what's next. */
    public function student(Request $request, Response $response): Response
    {
        /** @var User $me */
        $me = $request->getAttribute('user');
        $classIds = $this->studentClassIds($me);

        $topics = $this->publishedTopicsFor($me, $classIds);
        $lessonsViewed = (int) $this->em->getRepository(TopicProgress::class)->count(['student' => $me, 'lessonViewed' => true]);

        $attempts = $this->em->getRepository(AssessmentAttempt::class)->findBy(['student' => $me, 'status' => AssessmentAttempt::GRADED], ['submittedAt' => 'DESC']);
        $quizAvg = $this->avg(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $attempts));

        $pendingWorksheets = $this->pendingWorksheets($me, $classIds);
        $unreadFeedback = (int) $this->em->createQueryBuilder()->select('COUNT(f.id)')->from(FeedbackNote::class, 'f')
            ->where('f.student = :me')->andWhere('f.acknowledged = false')->setParameter('me', $me)->getQuery()->getSingleScalarResult();
        $unreadNotifs = (int) $this->em->createQueryBuilder()->select('COUNT(n.id)')->from(Notification::class, 'n')
            ->where('n.user = :me')->andWhere('n.read = false')->setParameter('me', $me)->getQuery()->getSingleScalarResult();

        return Json::write($response, [
            'stats' => [
                'topics' => count($topics),
                'lessons_viewed' => $lessonsViewed,
                'quizzes_taken' => count($attempts),
                'quiz_average' => $quizAvg,
            ],
            'action_items' => [
                'pending_worksheets' => $pendingWorksheets,
                'unread_feedback' => $unreadFeedback,
                'unread_notifications' => $unreadNotifs,
                'upcoming_live' => count($this->upcomingLiveForStudent($me, $classIds)),
            ],
            'recent_quizzes' => array_map(static fn (AssessmentAttempt $a) => [
                'assessment' => $a->getAssessment()->getTitle(),
                'subject' => $a->getAssessment()->getSubject()->getName(),
                'percentage' => $a->getPercentage(),
                'passed' => $a->isPassed(),
            ], array_slice($attempts, 0, 5)),
            'upcoming' => array_map(static fn (LiveClass $lc) => [
                'title' => $lc->getTitle(), 'subject' => $lc->getSubject()->getName(),
                'scheduled_at' => $lc->getScheduledAt()->format(DATE_ATOM), 'status' => $lc->getStatus(),
            ], $this->upcomingLiveForStudent($me, $classIds)),
        ]);
    }

    /** GET /dashboard/super-admin — platform overview. */
    public function superAdmin(Request $request, Response $response): Response
    {
        $subs = $this->em->getRepository(Subscription::class)->findAll();
        $active = array_filter($subs, static fn (Subscription $s) => in_array($s->status(), [Subscription::ACTIVE, Subscription::GRACE], true));
        $mrr = 0;
        foreach ($active as $s) {
            $mrr += $s->getPlan()->getPriceKobo();
        }

        return Json::write($response, [
            'stats' => [
                'institutions' => (int) $this->em->getRepository(Institution::class)->count([]),
                'active_subscriptions' => count($active),
                'plans' => (int) $this->em->getRepository(SubscriptionPlan::class)->count(['isActive' => true]),
                'students' => $this->countRole('student', null),
                'teachers' => $this->countRole('teacher', null),
                'billed_naira' => $mrr / 100,
            ],
            'institutions' => array_map(static fn (Institution $i) => [
                'id' => $i->getId(), 'name' => $i->getName(),
            ], array_slice($this->em->getRepository(Institution::class)->findAll(), 0, 8)),
            'plans' => array_map(fn (SubscriptionPlan $p) => [
                'name' => $p->getName(),
                'subscribers' => (int) $this->em->getRepository(Subscription::class)->count(['plan' => $p]),
            ], $this->em->getRepository(SubscriptionPlan::class)->findBy(['isActive' => true])),
        ]);
    }

    /** GET /dashboard/parent — each child at a glance. */
    public function parent(Request $request, Response $response): Response
    {
        /** @var User $me */
        $me = $request->getAttribute('user');
        $links = $this->em->getRepository(GuardianLink::class)->findBy(['guardian' => $me]);
        $children = [];
        foreach ($links as $link) {
            $child = $link->getStudent();
            $attempts = $this->em->getRepository(AssessmentAttempt::class)->findBy(['student' => $child, 'status' => AssessmentAttempt::GRADED]);
            $children[] = [
                'student_id' => $child->getId(),
                'name' => $child->getFirstName() . ' ' . $child->getLastName(),
                'quiz_average' => $this->avg(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $attempts)),
                'quizzes_taken' => count($attempts),
                'unread_feedback' => (int) $this->em->createQueryBuilder()->select('COUNT(f.id)')->from(FeedbackNote::class, 'f')
                    ->where('f.student = :c')->andWhere('f.acknowledged = false')->setParameter('c', $child)->getQuery()->getSingleScalarResult(),
            ];
        }

        return Json::write($response, ['children' => $children]);
    }

    // --- helpers ---

    /** @return AssessmentAttempt[] */
    private function gradedAttempts(?Institution $inst): array
    {
        $qb = $this->em->createQueryBuilder()->select('at')->from(AssessmentAttempt::class, 'at')
            ->join('at.assessment', 'a')->join('a.subject', 's')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return $qb->getQuery()->getResult();
    }

    private function quizBySubject(array $attempts): array
    {
        $by = [];
        foreach ($attempts as $a) {
            $name = $a->getAssessment()->getSubject()->getName();
            $by[$name] ??= ['subject' => $name, 'sum' => 0.0, 'n' => 0];
            $by[$name]['sum'] += (float) $a->getPercentage();
            $by[$name]['n']++;
        }
        return array_values(array_map(static fn (array $r) => [
            'subject' => $r['subject'], 'average' => round($r['sum'] / max(1, $r['n']), 1), 'attempts' => $r['n'],
        ], $by));
    }

    private function countRole(string $role, ?Institution $inst): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :role')->setParameter('role', $role);
        if ($inst !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countScoped(string $entity, string $alias, ?Institution $inst, ?string $instPath = null, ?string $joinSubject = null): int
    {
        $qb = $this->em->createQueryBuilder()->select("COUNT($alias.id)")->from($entity, $alias);
        if ($inst !== null) {
            if ($joinSubject !== null) {
                $qb->join($joinSubject, 'js')->andWhere('js.institution = :inst');
            } else {
                $qb->andWhere(($instPath ?? "$alias.institution") . ' = :inst');
            }
            $qb->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countPublished(string $entity, string $alias, ?Institution $inst): int
    {
        $qb = $this->em->createQueryBuilder()->select("COUNT($alias.id)")->from($entity, $alias)
            ->where("$alias.approvalStatus = :pub")->setParameter('pub', Lifecycle::PUBLISHED);
        if ($inst !== null) {
            $qb->join("$alias.subject", 's')->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countSubmissions(?Institution $inst, string $status): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(ws.id)')->from(WorksheetSubmission::class, 'ws')
            ->join('ws.worksheet', 'w')->join('w.topic', 't')->join('t.subject', 's')
            ->where('ws.status = :st')->setParameter('st', $status);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countPortfolio(?Institution $inst, string $status): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(PortfolioEntry::class, 'p')
            ->join('p.topic', 't')->join('t.subject', 's')
            ->where('p.status = :st')->setParameter('st', $status);
        if ($inst !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countInterventions(?Institution $inst, array $statuses): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Intervention::class, 'i')->join('i.student', 'st')
            ->where('i.status IN (:ss)')->setParameter('ss', $statuses);
        if ($inst !== null) {
            $qb->andWhere('st.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countSafeguarding(?Institution $inst): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(c.id)')->from(SafeguardingCase::class, 'c')
            ->where('c.status != :closed')->setParameter('closed', SafeguardingCase::CLOSED);
        if ($inst !== null) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', $inst);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function myUpcomingLive(User $me): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(lc.id)')->from(LiveClass::class, 'lc')
            ->where('lc.host = :me')->andWhere('lc.status IN (:open)')
            ->setParameter('me', $me)->setParameter('open', [LiveClass::SCHEDULED, LiveClass::LIVE])
            ->getQuery()->getSingleScalarResult();
    }

    /** @return LiveClass[] */
    private function upcomingLiveList(User $me): array
    {
        return $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')
            ->where('lc.host = :me')->andWhere('lc.status IN (:open)')
            ->setParameter('me', $me)->setParameter('open', [LiveClass::SCHEDULED, LiveClass::LIVE])
            ->orderBy('lc.scheduledAt', 'ASC')->setMaxResults(5)->getQuery()->getResult();
    }

    /** @return LiveClass[] */
    private function upcomingLiveForStudent(User $me, array $classIds): array
    {
        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->where('lc.status IN (:open)')->setParameter('open', [LiveClass::SCHEDULED, LiveClass::LIVE])
            ->orderBy('lc.scheduledAt', 'ASC')->setMaxResults(5);
        if ($me->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $me->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('lc.schoolClass IS NULL OR lc.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('lc.schoolClass IS NULL');
        }
        return $qb->getQuery()->getResult();
    }

    private function pendingWorksheets(User $me, array $classIds): int
    {
        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')->join('w.topic', 't')->join('t.subject', 's')
            ->where('w.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        if ($me->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $me->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('w.schoolClass IS NULL OR w.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        }
        $pending = 0;
        foreach ($qb->getQuery()->getResult() as $w) {
            $sub = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $w, 'student' => $me]);
            if ($sub === null) {
                $pending++;
            }
        }
        return $pending;
    }

    /** @return Topic[] */
    private function publishedTopicsFor(User $me, array $classIds): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        if ($me->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $me->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('t.schoolClass IS NULL OR t.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return int[] */
    private function studentClassIds(User $me): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $me]) as $e) {
            $ids[] = $e->getSchoolClass()->getId();
        }
        return array_values(array_unique($ids));
    }

    private function avg(array $nums): ?float
    {
        return $nums ? round(array_sum($nums) / count($nums), 1) : null;
    }
}
