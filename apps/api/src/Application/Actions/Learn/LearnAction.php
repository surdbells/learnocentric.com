<?php

declare(strict_types=1);

namespace App\Application\Actions\Learn;

use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\FeedbackNote;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\StudentTopicNote;
use App\Domain\Entity\Subject;
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

/**
 * The learner's topic journey (spec §13): each published topic surfaces its
 * lesson (delivery pack) plus quiz / worksheet / portfolio stages, with the
 * student's progress computed from the work they've already done.
 */
final class LearnAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /learn/topics — the student's topics with per-stage progress. */
    public function topics(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $rows = [];
        foreach ($this->publishedTopics($student) as $topic) {
            $stages = $this->stages($topic, $student);
            $rows[] = $this->summary($topic, $stages);
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** GET /learn/profile — the learner profile overview: snapshot + derived achievements. */
    public function profile(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);

        $lessons = (int) $this->em->getRepository(TopicProgress::class)->count(['student' => $student, 'lessonViewed' => true]);
        $attempts = $this->em->getRepository(AssessmentAttempt::class)->findBy(['student' => $student, 'status' => AssessmentAttempt::GRADED]);
        $quizzes = count($attempts);
        $avg = $quizzes > 0 ? (int) round(array_sum(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $attempts)) / $quizzes) : null;
        $worksheets = (int) $this->em->getRepository(WorksheetSubmission::class)->count(['student' => $student]);

        // Enrolled class label.
        $enrolment = $this->em->getRepository(Enrollment::class)->findOneBy(['student' => $student]);
        $classLabel = $enrolment?->getSchoolClass()->getLabel();

        return Json::write($response, [
            'member_since' => $student->getCreatedAt()->format(DATE_ATOM),
            'class_label' => $classLabel,
            'snapshot' => [
                'lessons_completed' => $lessons,
                'average_score' => $avg,
                'quizzes_taken' => $quizzes,
                'worksheets_done' => $worksheets,
            ],
            'achievements' => $this->achievements($lessons, $avg, $quizzes, $worksheets),
        ]);
    }

    /**
     * Achievements DERIVED from real activity (not a stored badge model) — each
     * unlocks at a real threshold, so nothing is fabricated.
     *
     * @return array<int,array{key:string,title:string,detail:string,earned:bool,icon:string}>
     */
    private function achievements(int $lessons, ?int $avg, int $quizzes, int $worksheets): array
    {
        return [
            ['key' => 'consistent', 'title' => 'Consistent Learner', 'detail' => 'Complete 10 lessons', 'earned' => $lessons >= 10, 'icon' => 'auto_stories'],
            ['key' => 'quiz_master', 'title' => 'Quiz Master', 'detail' => 'Score 80% or higher', 'earned' => $avg !== null && $avg >= 80, 'icon' => 'military_tech'],
            ['key' => 'first_steps', 'title' => 'First Steps', 'detail' => 'Complete your first lesson', 'earned' => $lessons >= 1, 'icon' => 'flag'],
            ['key' => 'quiz_taker', 'title' => 'Getting Tested', 'detail' => 'Take 3 quizzes', 'earned' => $quizzes >= 3, 'icon' => 'quiz'],
            ['key' => 'diligent', 'title' => 'Diligent', 'detail' => 'Submit 2 worksheets', 'earned' => $worksheets >= 2, 'icon' => 'task_alt'],
        ];
    }

    /** GET /learn/subjects — the learner's subjects with aggregate progress + next topic. */
    public function subjects(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        $bySubject = [];
        foreach ($this->publishedTopics($student) as $topic) {
            $summary = $this->summary($topic, $this->stages($topic, $student));
            $subject = $topic->getSubject();
            $sid = $subject->getId();
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = [
                    'id' => $sid,
                    'name' => $subject->getName(),
                    'topic_count' => 0,
                    'completed_topics' => 0,
                    'progress_total' => 0,
                    'next_topic' => null,
                    'teacher' => $this->teacherFor($subject, $classIds),
                ];
            }
            $bySubject[$sid]['topic_count']++;
            $bySubject[$sid]['progress_total'] += $summary['progress'];
            if ($summary['complete']) {
                $bySubject[$sid]['completed_topics']++;
            } elseif ($bySubject[$sid]['next_topic'] === null) {
                $bySubject[$sid]['next_topic'] = ['id' => $topic->getId(), 'title' => $summary['title'], 'week_number' => $summary['week_number']];
            }
        }

        $rows = [];
        foreach ($bySubject as $s) {
            $count = max(1, $s['topic_count']);
            $rows[] = [
                'id' => $s['id'],
                'name' => $s['name'],
                'topic_count' => $s['topic_count'],
                'completed_topics' => $s['completed_topics'],
                'progress' => (int) round($s['progress_total'] / $count),
                'next_topic' => $s['next_topic'],
                'teacher' => $s['teacher'],
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * GET /learn/subjects/{id} — one subject's whole world for a learner: its
     * lessons (topic journey), worksheets, quizzes, live classes, resources and
     * feedback, each scoped to this student. Every figure is real activity.
     */
    public function subject(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $subject = $this->em->getRepository(Subject::class)->find((int) $args['id']);
        if ($subject === null) {
            return Json::error($response, 'Subject not found.', 404);
        }
        if ($student->getInstitution() !== null
            && $subject->getInstitution()->getId() !== $student->getInstitution()->getId()) {
            return Json::error($response, 'This subject is not available to you.', 403);
        }

        $classIds = $this->studentClassIds($student);

        // Published topics of THIS subject that the student may see.
        $topics = array_values(array_filter(
            $this->publishedTopics($student),
            static fn (Topic $t) => $t->getSubject()->getId() === $subject->getId()
        ));

        // Lessons (topic journey) + aggregate progress + lesson resources.
        $lessons = [];
        $resources = [];
        $seenResources = [];
        $progressTotal = 0;
        $completed = 0;
        $nextTopic = null;
        foreach ($topics as $topic) {
            $summary = $this->summary($topic, $this->stages($topic, $student));
            $lessons[] = [
                'id' => $topic->getId(),
                'title' => $summary['title'],
                'week_number' => $summary['week_number'],
                'progress' => $summary['progress'],
                'completed_stages' => $summary['completed_stages'],
                'total_stages' => $summary['total_stages'],
                'next_stage' => $summary['next_stage'],
                'complete' => $summary['complete'],
                'objective' => $topic->toArray()['objective'] ?? null,
            ];
            $progressTotal += $summary['progress'];
            if ($summary['complete']) {
                $completed++;
            } elseif ($nextTopic === null) {
                $nextTopic = ['id' => $topic->getId(), 'title' => $summary['title'], 'week_number' => $summary['week_number']];
            }

            $pack = $this->em->getRepository(TopicDeliveryPack::class)->findOneBy(['topic' => $topic]);
            if ($pack !== null && $pack->getStatus() === Lifecycle::PUBLISHED) {
                $d = $pack->toArray();
                $addResource = function (array $r) use (&$resources, &$seenResources): void {
                    $key = (string) ($r['url'] ?? $r['path'] ?? '');
                    if ($key === '' || isset($seenResources[$key])) {
                        return;
                    }
                    $seenResources[$key] = true;
                    $resources[] = $r;
                };
                if (!empty($d['video_url'])) {
                    $addResource(['topic_id' => $topic->getId(), 'topic' => $summary['title'], 'type' => 'video', 'title' => 'Lesson video', 'url' => $d['video_url']]);
                }
                foreach (($d['media'] ?? []) as $m) {
                    $addResource(array_merge(['topic_id' => $topic->getId(), 'topic' => $summary['title']], is_array($m) ? $m : []));
                }
            }
        }

        // Worksheets on this subject's topics + my submission status.
        $worksheets = [];
        if (!empty($topics)) {
            foreach ($this->em->getRepository(Worksheet::class)->findBy(['topic' => $topics]) as $w) {
                if ($w->getApprovalStatus() !== Lifecycle::PUBLISHED) {
                    continue;
                }
                $sub = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $w, 'student' => $student]);
                $graded = $sub !== null && $sub->getStatus() === WorksheetSubmission::GRADED;
                $worksheets[] = [
                    'id' => $w->getId(),
                    'title' => $w->getTitle(),
                    'topic' => $w->getTopic()->getTitle(),
                    'total_marks' => $w->getTotalMarks(),
                    'status' => $sub === null ? 'not_started' : ($graded ? 'graded' : 'submitted'),
                    'score' => $graded ? $sub->getScore() : null,
                ];
            }
        }

        // Quizzes/assessments on this subject + my best graded attempt.
        $assessments = [];
        foreach ($this->em->getRepository(Assessment::class)->findBy(['subject' => $subject]) as $a) {
            if ($a->getApprovalStatus() !== Lifecycle::PUBLISHED) {
                continue;
            }
            $attempt = $this->em->getRepository(AssessmentAttempt::class)->findOneBy(
                ['assessment' => $a, 'student' => $student, 'status' => AssessmentAttempt::GRADED],
                ['percentage' => 'DESC']
            );
            $assessments[] = [
                'id' => $a->getId(),
                'title' => $a->getTitle(),
                'topic' => $a->getTopic()?->getTitle(),
                'attempted' => $attempt !== null,
                'best_score' => $attempt?->getPercentage(),
            ];
        }

        // Live classes for this subject, visible to the student's class (or class-agnostic).
        $liveClasses = [];
        $lcRows = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')
            ->where('lc.subject = :subj')->setParameter('subj', $subject)
            ->orderBy('lc.scheduledAt', 'DESC')->setMaxResults(12)
            ->getQuery()->getResult();
        foreach ($lcRows as $lc) {
            $cid = $lc->getSchoolClass()?->getId();
            if ($cid !== null && !empty($classIds) && !in_array($cid, $classIds, true)) {
                continue;
            }
            $liveClasses[] = [
                'id' => $lc->getId(),
                'title' => $lc->getTitle(),
                'topic' => $lc->getTopic()?->getTitle(),
                'scheduled_at' => $lc->getScheduledAt()->format(DATE_ATOM),
                'status' => $lc->getStatus(),
            ];
        }

        // Recent feedback tied to this subject's topics.
        $feedback = [];
        if (!empty($topics)) {
            foreach ($this->em->getRepository(FeedbackNote::class)->findBy(['student' => $student, 'topic' => $topics], ['id' => 'DESC'], 5) as $f) {
                $arr = $f->toArray();
                $feedback[] = [
                    'id' => $arr['id'],
                    'topic' => $arr['topic'],
                    'author' => $arr['author'],
                    'message' => $arr['message'],
                    'score' => $arr['score'],
                    'created_at' => $arr['created_at'],
                ];
            }
        }

        $count = max(1, count($topics));

        return Json::write($response, [
            'id' => $subject->getId(),
            'name' => $subject->getName(),
            'code' => $subject->toArray()['code'] ?? null,
            'teacher' => $this->teacherFor($subject, $classIds),
            'topic_count' => count($topics),
            'completed_topics' => $completed,
            'progress' => (int) round($progressTotal / $count),
            'next_topic' => $nextTopic,
            'lessons' => $lessons,
            'worksheets' => $worksheets,
            'assessments' => $assessments,
            'live_classes' => $liveClasses,
            'resources' => $resources,
            'feedback' => $feedback,
        ]);
    }

    /** The teacher assigned to a subject for the student's class(es), if any. */
    private function teacherFor(\App\Domain\Entity\Subject $subject, array $classIds): ?string
    {
        $qb = $this->em->createQueryBuilder()->select('u.firstName', 'u.lastName')
            ->from(\App\Domain\Entity\TeacherAssignment::class, 'ta')->join('ta.teacher', 'u')
            ->where('ta.subject = :subj')->setParameter('subj', $subject)->setMaxResults(1);
        if (!empty($classIds)) {
            $qb->andWhere('ta.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        }
        $row = $qb->getQuery()->getArrayResult()[0] ?? null;
        return $row ? trim($row['firstName'] . ' ' . $row['lastName']) : null;
    }

    /** GET /learn/topics/{id} — the lesson content + stage detail for one topic. */
    public function lesson(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $topic = $this->em->getRepository(Topic::class)->find((int) $args['id']);
        if ($topic === null || $topic->getApprovalStatus() !== Lifecycle::PUBLISHED) {
            return Json::error($response, 'Lesson not found.', 404);
        }
        if (!$this->studentCanSee($student, $topic)) {
            return Json::error($response, 'This lesson is not available to you.', 403);
        }

        $pack = $this->em->getRepository(TopicDeliveryPack::class)->findOneBy(['topic' => $topic]);
        $learnerPack = null;
        if ($pack !== null && $pack->getStatus() === Lifecycle::PUBLISHED) {
            $data = $pack->toArray();
            // Students see only the learner-facing parts of the pack.
            $learnerPack = [
                'learner_note' => $data['learner_note'],
                'video_url' => $data['video_url'],
                'media' => $data['media'],
                'worked_examples' => $data['worked_examples'],
            ];
        }
        $stages = $this->stages($topic, $student);

        return Json::write($response, $this->summary($topic, $stages) + [
            'objective' => $topic->toArray()['objective'],
            'real_life_relevance' => $topic->toArray()['real_life_relevance'],
            'lesson' => $learnerPack,
        ]);
    }

    /** POST /learn/topics/{id}/complete-lesson — mark the lesson viewed. */
    public function completeLesson(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $topic = $this->em->getRepository(Topic::class)->find((int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        $progress = $this->em->getRepository(TopicProgress::class)->findOneBy(['topic' => $topic, 'student' => $student]);
        if ($progress === null) {
            $progress = new TopicProgress($topic, $student);
            $this->em->persist($progress);
        }
        if (!$progress->isLessonViewed()) {
            $progress->setLessonViewed(true);
            $progress->setLessonViewedAt(new DateTimeImmutable());
        }
        $this->em->flush();

        return Json::write($response, $this->summary($topic, $this->stages($topic, $student)));
    }

    /** GET/PUT /learn/topics/{id}/note — the learner's personal notes for a topic. */
    public function note(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $topic = $this->em->getRepository(Topic::class)->find((int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        $note = $this->em->getRepository(StudentTopicNote::class)->findOneBy(['student' => $student, 'topic' => $topic]);

        if (strtoupper($request->getMethod()) === 'PUT') {
            $body = trim((string) (((array) $request->getParsedBody())['body'] ?? ''));
            if ($note === null) {
                $note = new StudentTopicNote($student, $topic);
                $this->em->persist($note);
            }
            $note->setBody($body);
            $this->em->flush();
        }

        return Json::write($response, $note?->toArray() ?? ['body' => '', 'updated_at' => null]);
    }

    // --- journey computation ---

    /** @return array<int,array<string,mixed>> */
    private function stages(Topic $topic, User $student): array
    {
        // Lesson: a published delivery pack + the student's viewed flag.
        $pack = $this->em->getRepository(TopicDeliveryPack::class)->findOneBy(['topic' => $topic]);
        $lessonAvailable = $pack !== null && $pack->getStatus() === Lifecycle::PUBLISHED;
        $progress = $this->em->getRepository(TopicProgress::class)->findOneBy(['topic' => $topic, 'student' => $student]);
        $lessonDone = $progress !== null && $progress->isLessonViewed();

        // Quiz: a published assessment on this topic + a graded attempt.
        $assessments = array_filter(
            $this->em->getRepository(Assessment::class)->findBy(['topic' => $topic]),
            static fn (Assessment $a) => $a->getApprovalStatus() === Lifecycle::PUBLISHED
        );
        $quizAvailable = count($assessments) > 0;
        $quizDetail = null;
        $quizDone = false;
        foreach ($assessments as $assessment) {
            $attempt = $this->em->getRepository(AssessmentAttempt::class)
                ->findOneBy(['assessment' => $assessment, 'student' => $student, 'status' => AssessmentAttempt::GRADED], ['percentage' => 'DESC']);
            if ($attempt !== null) {
                $quizDone = true;
                $quizDetail = $attempt->getPercentage() . '%';
                break;
            }
        }

        // Worksheet: a published worksheet on this topic + a submission.
        $worksheets = array_filter(
            $this->em->getRepository(Worksheet::class)->findBy(['topic' => $topic]),
            static fn (Worksheet $w) => $w->getApprovalStatus() === Lifecycle::PUBLISHED
        );
        $worksheetAvailable = count($worksheets) > 0;
        $worksheetDetail = null;
        $worksheetDone = false;
        foreach ($worksheets as $worksheet) {
            $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);
            if ($submission !== null) {
                $worksheetDone = true;
                $worksheetDetail = $submission->getStatus() === WorksheetSubmission::GRADED
                    ? $submission->getScore() . '/' . $worksheet->getTotalMarks()
                    : 'submitted';
                break;
            }
        }

        // Portfolio: competency evidence is always an available stage.
        $entry = $this->em->getRepository(PortfolioEntry::class)->findOneBy(['topic' => $topic, 'student' => $student]);
        $portfolioDone = $entry !== null;
        $portfolioDetail = $entry?->getCompetencyRating();

        return [
            ['key' => 'lesson', 'label' => 'Lesson', 'available' => $lessonAvailable, 'done' => $lessonDone, 'detail' => null, 'link' => null],
            ['key' => 'quiz', 'label' => 'Quiz', 'available' => $quizAvailable, 'done' => $quizDone, 'detail' => $quizDetail, 'link' => '/student/academics/assessments'],
            ['key' => 'worksheet', 'label' => 'Worksheet', 'available' => $worksheetAvailable, 'done' => $worksheetDone, 'detail' => $worksheetDetail, 'link' => '/student/academics/worksheets'],
            ['key' => 'portfolio', 'label' => 'Portfolio', 'available' => true, 'done' => $portfolioDone, 'detail' => $portfolioDetail, 'link' => '/student/academics/portfolio'],
        ];
    }

    private function summary(Topic $topic, array $stages): array
    {
        $available = array_filter($stages, static fn (array $s) => $s['available']);
        $doneCount = count(array_filter($available, static fn (array $s) => $s['done']));
        $total = max(1, count($available));
        // Next action = first available-but-not-done stage.
        $next = null;
        foreach ($stages as $stage) {
            if ($stage['available'] && !$stage['done']) {
                $next = $stage['key'];
                break;
            }
        }
        $data = $topic->toArray();

        return [
            'id' => $topic->getId(),
            'title' => $data['title'],
            'subject' => $data['subject'],
            'week_number' => $data['week_number'],
            'stages' => array_values($stages),
            'progress' => (int) round($doneCount / $total * 100),
            'completed_stages' => $doneCount,
            'total_stages' => count($available),
            'next_stage' => $next,
            'complete' => $next === null,
        ];
    }

    /** @return Topic[] */
    private function publishedTopics(User $student): array
    {
        $classIds = $this->studentClassIds($student);
        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)
            ->orderBy('t.weekNumber', 'ASC');
        if ($student->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $student->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('t.schoolClass IS NULL OR t.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        }
        return $qb->getQuery()->getResult();
    }

    private function studentCanSee(User $student, Topic $topic): bool
    {
        if ($student->getInstitution() !== null
            && $topic->getSubject()->getInstitution()->getId() !== $student->getInstitution()->getId()) {
            return false;
        }
        return true;
    }

    /** @return int[] */
    private function studentClassIds(User $student): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $student]) as $enrollment) {
            $ids[] = $enrollment->getSchoolClass()->getId();
        }
        return array_values(array_unique($ids));
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }
}
