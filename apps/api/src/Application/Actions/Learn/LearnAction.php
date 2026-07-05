<?php

declare(strict_types=1);

namespace App\Application\Actions\Learn;

use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\PortfolioEntry;
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
