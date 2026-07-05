<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\AssessmentQuestion;
use App\Domain\Entity\AttemptAnswer;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Question;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Application\Support\Json;
use App\Service\AnswerGrader;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Learner quiz-taking: list available published assessments, start an attempt,
 * and submit for auto-grading against the validated answers (spec §14 flow).
 */
final class AttemptsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnswerGrader $grader,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /assessment/attempts/available — published assessments the student can take. */
    public function available(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $institution = $student->getInstitution();
        $classIds = $this->studentClassIds($student);

        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('a.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)
            ->orderBy('a.createdAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if (!empty($classIds)) {
            $qb->andWhere('a.schoolClass IS NULL OR a.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('a.schoolClass IS NULL');
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $assessment) {
            /** @var Assessment $assessment */
            $attempt = $this->em->getRepository(AssessmentAttempt::class)
                ->findOneBy(['assessment' => $assessment, 'student' => $student], ['id' => 'DESC']);
            $rows[] = [
                'id' => $assessment->getId(),
                'title' => $assessment->getTitle(),
                'subject' => $assessment->getSubject()->getName(),
                'type' => $assessment->getType(),
                'track' => $assessment->getTrack(),
                'question_count' => $assessment->getItems()->count(),
                'total_marks' => $assessment->totalMarks(),
                'duration_minutes' => $assessment->toArray()['duration_minutes'],
                'pass_mark' => $assessment->getPassMark(),
                'attempt' => $attempt?->toArray(),
            ];
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** POST /assessment/attempts — start (or resume) an attempt for { assessment_id }. */
    public function start(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $assessment = $this->em->getRepository(Assessment::class)->find((int) ($body['assessment_id'] ?? 0));
        if ($assessment === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if ($assessment->getApprovalStatus() !== Lifecycle::PUBLISHED) {
            return Json::error($response, 'This assessment is not open for attempts.', 422);
        }

        $existing = $this->em->getRepository(AssessmentAttempt::class)
            ->findOneBy(['assessment' => $assessment, 'student' => $student, 'status' => AssessmentAttempt::IN_PROGRESS]);
        if ($existing !== null) {
            return Json::write($response, $this->takeView($existing));
        }

        $attempt = new AssessmentAttempt($assessment, $student);
        $attempt->setTrack($assessment->getTrack());
        $attempt->setTotalMarks($assessment->totalMarks());
        $attempt->setStartedAt(new DateTimeImmutable());
        $this->em->persist($attempt);
        $this->em->flush();
        $this->audit->log('attempt.start', $student, 'AssessmentAttempt', (string) $attempt->getId(), null, ['assessment_id' => $assessment->getId()]);

        return Json::write($response, $this->takeView($attempt), 201);
    }

    /** GET /assessment/attempts/{id} — owner (or staff) view of an attempt. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $attempt = $this->em->getRepository(AssessmentAttempt::class)->find((int) $args['id']);
        if ($attempt === null) {
            return Json::error($response, 'Attempt not found.', 404);
        }
        $user = $this->currentUser($request);
        if (!$this->canSee($user, $attempt)) {
            return Json::error($response, 'You are not allowed to view this attempt.', 403);
        }

        if ($attempt->getStatus() === AssessmentAttempt::GRADED) {
            return Json::write($response, $this->resultView($attempt));
        }

        return Json::write($response, $this->takeView($attempt));
    }

    /** POST /assessment/attempts/{id}/submit — submit answers and auto-grade. */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $attempt = $this->em->getRepository(AssessmentAttempt::class)->find((int) $args['id']);
        if ($attempt === null) {
            return Json::error($response, 'Attempt not found.', 404);
        }
        $student = $this->currentUser($request);
        if ($attempt->getStudent()->getId() !== $student->getId()) {
            return Json::error($response, 'You can only submit your own attempt.', 403);
        }
        if ($attempt->getStatus() === AssessmentAttempt::GRADED) {
            return Json::error($response, 'This attempt has already been submitted.', 409);
        }

        $responses = [];
        foreach ((array) (($request->getParsedBody()['answers'] ?? [])) as $entry) {
            if (isset($entry['question_id'])) {
                $responses[(int) $entry['question_id']] = $entry['response'] ?? null;
            }
        }

        $score = 0;
        $total = 0;
        foreach ($attempt->getAssessment()->getItems() as $item) {
            /** @var AssessmentQuestion $item */
            $question = $item->getQuestion();
            $total += $item->effectiveMarks();
            $response_ = $responses[$question->getId()] ?? null;
            $graded = $this->grader->grade($question, $response_);
            // Cap awarded marks at the item's marks (which may override the question's).
            $awarded = $graded['correct'] ? $item->effectiveMarks() : 0;

            $answer = $attempt->findAnswer((int) $question->getId());
            if ($answer === null) {
                $answer = new AttemptAnswer($attempt, $question);
                $this->em->persist($answer);
                $attempt->getAnswers()->add($answer);
            }
            $answer->setResponse($response_);
            $answer->setCorrect($graded['correct']);
            $answer->setMarksAwarded($awarded);
            $score += $awarded;
        }

        $percentage = $total > 0 ? round($score / $total * 100, 1) : 0.0;
        $attempt->setTotalMarks($total);
        $attempt->setScore($score);
        $attempt->setPercentage($percentage);
        $attempt->setPassed($percentage >= $attempt->getAssessment()->getPassMark());
        $attempt->setStatus(AssessmentAttempt::GRADED);
        $attempt->setSubmittedAt(new DateTimeImmutable());
        $this->em->flush();
        $this->audit->log('attempt.submit', $student, 'AssessmentAttempt', (string) $attempt->getId(), null, ['score' => $score, 'total' => $total, 'passed' => $attempt->isPassed()]);

        return Json::write($response, $this->resultView($attempt));
    }

    // --- views ---

    /** Student-facing question, WITHOUT the correct answer. */
    private function takeView(AssessmentAttempt $attempt): array
    {
        $questions = [];
        foreach ($attempt->getAssessment()->getItems() as $item) {
            /** @var AssessmentQuestion $item */
            $q = $item->getQuestion();
            $options = null;
            if ($q->getType() === 'mcq' && is_array($q->getOptions())) {
                $options = array_map(static fn ($o) => ['key' => $o['key'] ?? '', 'text' => $o['text'] ?? ''], $q->getOptions());
            }
            $questions[] = [
                'question_id' => $q->getId(),
                'position' => $item->getPosition(),
                'stem' => $q->getStem(),
                'type' => $q->getType(),
                'options' => $options,
                'marks' => $item->effectiveMarks(),
            ];
        }

        return $attempt->toArray() + ['questions' => $questions];
    }

    /** Graded view: includes each answer's correctness and the question explanation. */
    private function resultView(AssessmentAttempt $attempt): array
    {
        $explanations = [];
        foreach ($attempt->getAssessment()->getItems() as $item) {
            $explanations[$item->getQuestion()->getId()] = [
                'correct_answer' => $item->getQuestion()->getCorrectAnswer(),
                'explanation' => $item->getQuestion()->toArray()['explanation'],
            ];
        }
        $data = $attempt->toArray(true);
        foreach ($data['answers'] as &$ans) {
            $extra = $explanations[$ans['question_id']] ?? [];
            $ans['correct_answer'] = $extra['correct_answer'] ?? null;
            $ans['explanation'] = $extra['explanation'] ?? null;
        }

        return $data;
    }

    // --- helpers ---

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
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

    private function canSee(User $user, AssessmentAttempt $attempt): bool
    {
        if ($attempt->getStudent()->getId() === $user->getId()) {
            return true;
        }
        $role = $user->getRole()->getCode();
        if (in_array($role, ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'], true)) {
            // Staff can see attempts within their institution.
            return $user->getInstitution() === null
                || $attempt->getAssessment()->getSubject()->getInstitution()?->getId() === $user->getInstitution()->getId();
        }
        return false;
    }
}
