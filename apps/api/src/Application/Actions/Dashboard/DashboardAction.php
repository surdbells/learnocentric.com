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

        $subjIds = array_keys($subjectIds);
        $pending = $this->pendingSubmissions($subjIds);
        $todaySchedule = $this->teacherTodaySchedule($me);
        $currentTopics = $this->teacherCurrentTopics($assignments);

        return Json::write($response, [
            'stats' => [
                'my_classes' => count($classIds),
                'my_subjects' => count($subjectIds),
                'my_students' => $students,
                'upcoming_live' => $this->myUpcomingLive($me),
                'pending_reviews' => count($pending),
                'upcoming_assessments' => $this->upcomingAssessments($subjIds),
                'today_classes' => count($todaySchedule),
                'current_topics' => count($currentTopics),
                'learners_needing_attention' => $this->learnersNeedingAttention(array_keys($classIds)),
            ],
            'action_items' => [
                'worksheets_to_grade' => $this->countSubmissions($inst, WorksheetSubmission::SUBMITTED),
                'portfolio_to_review' => $this->countPortfolio($inst, PortfolioEntry::SUBMITTED),
                'my_interventions' => (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Intervention::class, 'i')
                    ->where('i.assignedTo = :me')->andWhere('i.status IN (:open)')
                    ->setParameter('me', $me)->setParameter('open', [Intervention::OPEN, Intervention::IN_PROGRESS])
                    ->getQuery()->getSingleScalarResult(),
            ],
            'pending_submissions' => $pending,
            'class_performance' => $this->classPerformance($assignments),
            'today_schedule' => $todaySchedule,
            'current_topics' => $currentTopics,
            'upcoming' => $this->upcomingLiveList($me),
        ]);
    }

    /** Today's live classes hosted by the teacher, in time order. */
    private function teacherTodaySchedule(User $me): array
    {
        $start = new \DateTimeImmutable('today 00:00');
        $rows = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')
            ->where('lc.host = :me')->andWhere('lc.scheduledAt >= :s')->andWhere('lc.scheduledAt < :e')
            ->setParameter('me', $me)->setParameter('s', $start)->setParameter('e', $start->modify('+1 day'))
            ->orderBy('lc.scheduledAt', 'ASC')->getQuery()->getResult();
        return array_map(static fn (LiveClass $lc) => [
            'id' => $lc->getId(),
            'title' => $lc->getTitle(),
            'class' => $lc->getSchoolClass()?->getLabel(),
            'subject' => $lc->getSubject()->getName(),
            'topic' => $lc->getTopic()?->getTitle(),
            'scheduled_at' => $lc->getScheduledAt()->format(DATE_ATOM),
            'status' => $lc->getStatus(),
        ], $rows);
    }

    /**
     * Per class×subject the teacher takes: the current (highest-week) published
     * topic, the class's graded-attempt average, and a derived delivery status.
     *
     * @param TeacherAssignment[] $assignments
     */
    private function teacherCurrentTopics(array $assignments): array
    {
        $seen = [];
        $rows = [];
        foreach ($assignments as $a) {
            $class = $a->getSchoolClass();
            $subject = $a->getSubject();
            $key = $class->getId() . '-' . $subject->getId();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $topic = $this->em->createQueryBuilder()->select('t.title')->from(Topic::class, 't')
                ->where('t.subject = :subj')->andWhere('t.approvalStatus = :pub')
                ->andWhere('t.schoolClass = :cls OR t.schoolClass IS NULL')
                ->setParameter('subj', $subject)->setParameter('pub', Lifecycle::PUBLISHED)->setParameter('cls', $class)
                ->orderBy('t.weekNumber', 'DESC')->setMaxResults(1)->getQuery()->getArrayResult();

            $avgRow = $this->em->createQueryBuilder()->select('AVG(at.percentage) AS avg')
                ->from(AssessmentAttempt::class, 'at')->join('at.student', 'st')
                ->join(Enrollment::class, 'e', \Doctrine\ORM\Query\Expr\Join::WITH, 'e.student = st')
                ->where('e.schoolClass = :cid')->andWhere('at.status = :g')
                ->setParameter('cid', $class->getId())->setParameter('g', AssessmentAttempt::GRADED)
                ->getQuery()->getSingleScalarResult();
            $avg = $avgRow === null ? null : (int) round((float) $avgRow);

            $rows[] = [
                'class' => $class->getLabel(),
                'subject' => $subject->getName(),
                'topic' => $topic[0]['title'] ?? null,
                'average' => $avg,
                'delivery_status' => $avg !== null && $avg < 60 ? 'needs_attention' : 'on_track',
            ];
        }
        return $rows;
    }

    /** @param int[] $classIds distinct learners in the teacher's classes with an open intervention. */
    private function learnersNeedingAttention(array $classIds): int
    {
        if ($classIds === []) {
            return 0;
        }
        return (int) $this->em->createQueryBuilder()->select('COUNT(DISTINCT i.student)')->from(Intervention::class, 'i')
            ->join(Enrollment::class, 'e', \Doctrine\ORM\Query\Expr\Join::WITH, 'e.student = i.student')
            ->where('e.schoolClass IN (:cids)')->andWhere('i.status != :res')
            ->setParameter('cids', $classIds)->setParameter('res', Intervention::RESOLVED)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Submissions awaiting the teacher's review across worksheets and portfolio,
     * scoped to the subjects they teach. Newest first, capped for the panel.
     *
     * @param int[] $subjectIds
     * @return array<int, array<string, mixed>>
     */
    private function pendingSubmissions(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }
        $rows = [];

        $ws = $this->em->createQueryBuilder()->select('ws', 'w', 't', 'st')->from(WorksheetSubmission::class, 'ws')
            ->join('ws.worksheet', 'w')->join('w.topic', 't')->join('ws.student', 'st')
            ->where('ws.status = :st')->andWhere('t.subject IN (:subs)')
            ->setParameter('st', WorksheetSubmission::SUBMITTED)->setParameter('subs', $subjectIds)
            ->orderBy('ws.id', 'DESC')->setMaxResults(8)->getQuery()->getResult();
        foreach ($ws as $s) {
            /** @var WorksheetSubmission $s */
            $rows[] = [
                'learner' => $s->getStudent()->getFirstName() . ' ' . $s->getStudent()->getLastName(),
                'type' => 'Worksheet',
                'topic' => $s->getWorksheet()->getTopic()->getTitle(),
                'subject' => $s->getWorksheet()->getTopic()->getSubject()->getName(),
                'submitted_at' => $s->getCreatedAt()->format(DATE_ATOM),
                'status' => 'Pending review',
            ];
        }

        $pf = $this->em->createQueryBuilder()->select('p', 't', 'st')->from(PortfolioEntry::class, 'p')
            ->join('p.topic', 't')->join('p.student', 'st')
            ->where('p.status = :st')->andWhere('t.subject IN (:subs)')
            ->setParameter('st', PortfolioEntry::SUBMITTED)->setParameter('subs', $subjectIds)
            ->orderBy('p.id', 'DESC')->setMaxResults(8)->getQuery()->getResult();
        foreach ($pf as $p) {
            /** @var PortfolioEntry $p */
            $rows[] = [
                'learner' => $p->getStudent()->getFirstName() . ' ' . $p->getStudent()->getLastName(),
                'type' => 'Portfolio task',
                'topic' => $p->getTopic()->getTitle(),
                'subject' => $p->getTopic()->getSubject()->getName(),
                'submitted_at' => $p->toArray()['submitted_at'] ?? $p->toArray()['created_at'] ?? null,
                'status' => 'Pending review',
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp((string) $b['submitted_at'], (string) $a['submitted_at']));
        return array_slice($rows, 0, 8);
    }

    /**
     * Average graded-attempt score per class the teacher is assigned to.
     *
     * @param TeacherAssignment[] $assignments
     * @return array<int, array{class:string, average:float|null, attempts:int}>
     */
    private function classPerformance(array $assignments): array
    {
        $seen = [];
        $out = [];
        foreach ($assignments as $a) {
            $class = $a->getSchoolClass();
            $cid = $class->getId();
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $row = $this->em->createQueryBuilder()->select('AVG(at.percentage) AS avg', 'COUNT(at.id) AS c')
                ->from(AssessmentAttempt::class, 'at')->join('at.student', 'st')
                ->join(Enrollment::class, 'e', \Doctrine\ORM\Query\Expr\Join::WITH, 'e.student = st')
                ->where('e.schoolClass = :cid')->andWhere('at.status = :g')
                ->setParameter('cid', $cid)->setParameter('g', AssessmentAttempt::GRADED)
                ->getQuery()->getSingleResult();
            $out[] = [
                'class' => $class->getLabel(),
                'average' => $row['avg'] === null ? null : round((float) $row['avg'], 1),
                'attempts' => (int) $row['c'],
            ];
        }
        return $out;
    }

    /** @param int[] $subjectIds */
    private function upcomingAssessments(array $subjectIds): int
    {
        if ($subjectIds === []) {
            return 0;
        }
        return (int) $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(Assessment::class, 'a')
            ->where('a.approvalStatus = :pub')->andWhere('a.subject IN (:subs)')
            ->setParameter('pub', Lifecycle::PUBLISHED)->setParameter('subs', $subjectIds)
            ->getQuery()->getSingleScalarResult();
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

        // Viewed-topic set for progress/continue-learning.
        $viewed = [];
        foreach ($this->em->getRepository(TopicProgress::class)->findBy(['student' => $me, 'lessonViewed' => true]) as $tp) {
            $viewed[$tp->getTopic()->getId()] = true;
        }
        $subjects = $this->subjectProgress($topics, $viewed);
        $portfolio = $this->portfolioProgress($me, $topics);
        $worksheet = $this->worksheetProgress($me, $classIds);
        $latest = $attempts[0] ?? null;

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
            'continue_learning' => $this->continueLearning($subjects, $topics),
            'class_label' => $this->em->getRepository(Enrollment::class)->findOneBy(['student' => $me])?->getSchoolClass()->getLabel(),
            'progress' => [
                'lessons' => ['done' => $lessonsViewed, 'total' => count($topics), 'pct' => count($topics) ? (int) round($lessonsViewed / count($topics) * 100) : 0],
                'quiz_average' => $quizAvg,
                'worksheet' => $worksheet,
                'portfolio' => $portfolio,
            ],
            'mastery' => $this->masteryLabel($quizAvg),
            'latest_quiz' => $latest === null ? null : [
                'assessment' => $latest->getAssessment()->getTitle(),
                'score' => $latest->getScore(), 'total_marks' => $latest->getTotalMarks(),
                'percentage' => $latest->getPercentage(),
            ],
            'recent_subjects' => array_slice($subjects, 0, 4),
            'weak_areas' => $this->weakAreas($topics),
            'due_tasks' => $this->dueTasks($me, $classIds),
            'latest_feedback' => $this->latestFeedback($me),
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

    /**
     * Per-subject lesson progress from the published topics + the viewed set.
     *
     * @param Topic[] $topics
     * @param array<int, bool> $viewed
     * @return array<int, array{subject:string, total:int, viewed:int, progress_pct:int, last_topic:?string, next_topic:?string}>
     */
    private function subjectProgress(array $topics, array $viewed): array
    {
        $by = [];
        foreach ($topics as $t) {
            $sid = $t->getSubject()->getId();
            $by[$sid] ??= ['subject' => $t->getSubject()->getName(), 'total' => 0, 'viewed' => 0, 'last_topic' => null, 'next_topic' => null];
            $by[$sid]['total']++;
            if (isset($viewed[$t->getId()])) {
                $by[$sid]['viewed']++;
                $by[$sid]['last_topic'] = $t->getTitle();
            } elseif ($by[$sid]['next_topic'] === null) {
                $by[$sid]['next_topic'] = $t->getTitle();
            }
        }
        $out = array_map(static function (array $r) {
            $r['progress_pct'] = $r['total'] ? (int) round($r['viewed'] / $r['total'] * 100) : 0;
            return $r;
        }, array_values($by));
        usort($out, static fn ($a, $b) => $b['progress_pct'] <=> $a['progress_pct']);
        return $out;
    }

    /** @param array<int, array<string,mixed>> $subjects */
    /** @param Topic[] $topics */
    private function continueLearning(array $subjects, array $topics): ?array
    {
        // The subject with progress but not yet complete; else the first.
        $pick = null;
        foreach ($subjects as $s) {
            if ($s['progress_pct'] > 0 && $s['progress_pct'] < 100) {
                $pick = $s;
                break;
            }
        }
        $pick ??= $subjects[0] ?? null;
        if ($pick === null) {
            return null;
        }
        $title = $pick['next_topic'] ?? $pick['last_topic'];

        // Pull the topic's objective + week for the "today's lesson" card.
        $objective = null;
        $week = null;
        foreach ($topics as $t) {
            if ($t->getTitle() === $title && $t->getSubject()->getName() === $pick['subject']) {
                $data = $t->toArray();
                $objective = $data['objective'] ?? null;
                $week = $data['week_number'] ?? null;
                break;
            }
        }

        return ['subject' => $pick['subject'], 'topic' => $title, 'completion_pct' => $pick['progress_pct'], 'objective' => $objective, 'week_number' => $week];
    }

    /** @param Topic[] $topics @return array{done:int, total:int, pct:int} */
    private function portfolioProgress(User $me, array $topics): array
    {
        $total = 0;
        foreach ($topics as $t) {
            if (!empty($t->toArray()['portfolio_evidence_expected'])) {
                $total++;
            }
        }
        $done = (int) $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(PortfolioEntry::class, 'p')
            ->where('p.student = :me')->andWhere('p.status = :rev')
            ->setParameter('me', $me)->setParameter('rev', PortfolioEntry::REVIEWED)->getQuery()->getSingleScalarResult();
        return ['done' => $done, 'total' => $total, 'pct' => $total ? (int) round(min($done, $total) / $total * 100) : 0];
    }

    /** @param int[] $classIds @return array{done:int, total:int, pct:int} */
    private function worksheetProgress(User $me, array $classIds): array
    {
        $total = 0;
        if ($classIds !== []) {
            $total = (int) $this->em->createQueryBuilder()->select('COUNT(w.id)')->from(Worksheet::class, 'w')
                ->where('w.schoolClass IN (:cids) OR w.schoolClass IS NULL')->setParameter('cids', $classIds)
                ->getQuery()->getSingleScalarResult();
        }
        $done = (int) $this->em->createQueryBuilder()->select('COUNT(ws.id)')->from(WorksheetSubmission::class, 'ws')
            ->where('ws.student = :me')->andWhere('ws.status = :g')
            ->setParameter('me', $me)->setParameter('g', WorksheetSubmission::GRADED)->getQuery()->getSingleScalarResult();
        return ['done' => $done, 'total' => $total, 'pct' => $total ? (int) round(min($done, $total) / $total * 100) : 0];
    }

    private function masteryLabel(?float $avg): string
    {
        if ($avg === null) {
            return 'Not yet rated';
        }
        return $avg >= 80 ? 'Mastery' : ($avg >= 65 ? 'Proficient' : ($avg >= 50 ? 'Developing' : 'Emerging'));
    }

    /** @param Topic[] $topics @return string[] */
    private function weakAreas(array $topics): array
    {
        $out = [];
        foreach ($topics as $t) {
            $m = $t->toArray()['misconceptions'];
            if (is_array($m)) {
                foreach ($m as $item) {
                    $out[] = (string) $item;
                }
            }
        }
        return array_values(array_slice(array_unique($out), 0, 4));
    }

    /**
     * Upcoming worksheet + portfolio tasks with their due dates.
     *
     * @param int[] $classIds
     * @return array<int, array{title:string, type:string, due:?string}>
     */
    private function dueTasks(User $me, array $classIds): array
    {
        $tasks = [];
        if ($classIds !== []) {
            $submitted = [];
            foreach ($this->em->getRepository(WorksheetSubmission::class)->findBy(['student' => $me]) as $ws) {
                $submitted[$ws->getWorksheet()->getId()] = true;
            }
            $worksheets = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')
                ->where('w.schoolClass IN (:cids) OR w.schoolClass IS NULL')->andWhere('w.approvalStatus = :pub')
                ->setParameter('cids', $classIds)->setParameter('pub', Lifecycle::PUBLISHED)
                ->orderBy('w.dueDate', 'ASC')->setMaxResults(10)->getQuery()->getResult();
            foreach ($worksheets as $w) {
                /** @var Worksheet $w */
                if (isset($submitted[$w->getId()])) {
                    continue;
                }
                $tasks[] = ['title' => $w->getTitle(), 'type' => 'Worksheet', 'due' => $w->getDueDate()?->format('Y-m-d')];
            }
        }
        return array_slice($tasks, 0, 5);
    }

    private function latestFeedback(User $me): ?array
    {
        $f = $this->em->createQueryBuilder()->select('f')->from(FeedbackNote::class, 'f')
            ->where('f.student = :me')->setParameter('me', $me)->orderBy('f.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getResult();
        if ($f === []) {
            return null;
        }
        $a = $f[0]->toArray();
        return [
            'message' => $a['message'],
            'author' => $a['author'],
            'practice_needed' => $a['practice_needed'],
            'common_error' => $a['common_error'] ?? null,
            'next_step' => $a['next_step'] ?? null,
        ];
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
