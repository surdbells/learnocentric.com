<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Entity\WorksheetQuestion;
use App\Domain\Entity\WorksheetResponse;
use App\Domain\Entity\WorksheetSubmission;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Student worksheet submissions and staff grading. */
final class WorksheetSubmissionsAction
{
    use \App\Application\Actions\School\ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly \App\Service\NotificationService $notify,
    ) {
    }

    /** GET /assessment/worksheets/available — published worksheets for the student + their submission. */
    public function available(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $institution = $student->getInstitution();
        $classIds = $this->studentClassIds($student);

        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')->join('w.topic', 't')->join('t.subject', 's')
            ->where('w.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)->orderBy('w.createdAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if (!empty($classIds)) {
            $qb->andWhere('w.schoolClass IS NULL OR w.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('w.schoolClass IS NULL');
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $worksheet) {
            /** @var Worksheet $worksheet */
            $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);
            $qCount = (int) $this->em->getRepository(WorksheetQuestion::class)->count(['worksheet' => $worksheet]);
            $rows[] = $worksheet->toArray() + ['submission' => $submission?->toArray(), 'question_count' => $qCount];
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** POST /assessment/worksheets/{id}/submit — student submits (or updates before grading). */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        if ($worksheet->getApprovalStatus() !== Lifecycle::PUBLISHED) {
            return Json::error($response, 'This worksheet is not open for submissions.', 422);
        }
        $body = (array) $request->getParsedBody();
        $questions = $this->em->getRepository(WorksheetQuestion::class)->findBy(['worksheet' => $worksheet]);

        $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);
        if ($submission !== null && $submission->getStatus() === WorksheetSubmission::GRADED) {
            return Json::error($response, 'This worksheet has already been graded and cannot be resubmitted.', 409);
        }

        // Structured (per-question) worksheet: capture responses + hybrid auto-grade.
        if ($questions !== []) {
            if ($submission === null) {
                $submission = new WorksheetSubmission($worksheet, $student);
                $this->em->persist($submission);
                $this->em->flush(); // need an id for responses
            }
            $responses = $this->upsertResponses($submission, $questions, (array) ($body['responses'] ?? []));

            $autoMarks = 0;
            $hasFreeResponse = false;
            foreach ($responses as $resp) {
                $q = $resp->getQuestion();
                if (in_array($q->getType(), WorksheetQuestion::AUTO_TYPES, true)) {
                    [$correct, $awarded] = $this->autoMark($q, $resp->getAnswer());
                    $resp->setCorrect($correct);
                    $resp->setAwardedMarks($awarded);
                    $autoMarks += $awarded;
                } else {
                    $hasFreeResponse = true;
                    $resp->setAwardedMarks(null);
                    $resp->setCorrect(null);
                }
            }
            $submission->setScore($autoMarks);
            $submission->setStatus($hasFreeResponse ? WorksheetSubmission::SUBMITTED : WorksheetSubmission::GRADED);
            $submission->setSubmittedAt(new DateTimeImmutable());
            if (!$hasFreeResponse) {
                $submission->setGradedAt(new DateTimeImmutable());
            }
            $this->em->flush();
            $this->audit->log('worksheet.submit', $student, 'WorksheetSubmission', (string) $submission->getId(), null, ['worksheet_id' => $worksheet->getId(), 'auto_marks' => $autoMarks]);

            return Json::write($response, $this->solvePayload($worksheet, $submission));
        }

        // Free-form worksheet (no questions): the original response_text / attachment path.
        $text = trim((string) ($body['response_text'] ?? ''));
        $attachment = trim((string) ($body['attachment_url'] ?? ''));
        if ($text === '' && $attachment === '') {
            return Json::error($response, 'Add a written response or attach a file before submitting.', 422);
        }
        if ($submission === null) {
            $submission = new WorksheetSubmission($worksheet, $student);
            $this->em->persist($submission);
        }
        $submission->setResponseText($text !== '' ? $text : null);
        $submission->setAttachmentUrl($attachment !== '' ? $attachment : null);
        $submission->setStatus(WorksheetSubmission::SUBMITTED);
        $submission->setSubmittedAt(new DateTimeImmutable());
        $this->em->flush();
        $this->audit->log('worksheet.submit', $student, 'WorksheetSubmission', (string) $submission->getId(), null, ['worksheet_id' => $worksheet->getId()]);

        return Json::write($response, $submission->toArray());
    }

    /** GET /assessment/worksheets/{id}/solve — the solver payload (sections, questions, my draft, progress). */
    public function solve(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);

        return Json::write($response, $this->solvePayload($worksheet, $submission));
    }

    /** POST /assessment/worksheets/{id}/save — autosave the student's in-progress answers. */
    public function save(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $questions = $this->em->getRepository(WorksheetQuestion::class)->findBy(['worksheet' => $worksheet]);
        if ($questions === []) {
            return Json::error($response, 'This worksheet has no questions to save.', 422);
        }
        $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);
        if ($submission !== null && $submission->getStatus() === WorksheetSubmission::GRADED) {
            return Json::error($response, 'This worksheet has already been graded.', 409);
        }
        if ($submission === null) {
            $submission = new WorksheetSubmission($worksheet, $student);
            $submission->setStatus(WorksheetSubmission::DRAFT);
            $this->em->persist($submission);
            $this->em->flush();
        }
        $this->upsertResponses($submission, $questions, (array) ($request->getParsedBody()['responses'] ?? []));
        if ($submission->getStatus() !== WorksheetSubmission::SUBMITTED) {
            $submission->setStatus(WorksheetSubmission::DRAFT);
        }
        $this->em->flush();

        return Json::write($response, ['ok' => true, 'saved_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    /** POST /assessment/worksheets/{id}/questions — staff define the worksheet's questions (bulk replace). */
    public function setQuestions(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null || !$this->canActWithin($request, $worksheet->getTopic()->getSubject()->getInstitution())) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $items = $request->getParsedBody()['questions'] ?? null;
        if (!is_array($items)) {
            return Json::error($response, 'Provide a "questions" array.', 422);
        }
        // Replace existing questions.
        foreach ($this->em->getRepository(WorksheetQuestion::class)->findBy(['worksheet' => $worksheet]) as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();

        $total = 0;
        $sectionPositions = [];
        $pos = 0;
        foreach (array_values($items) as $item) {
            $prompt = trim((string) ($item['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $q = new WorksheetQuestion($worksheet, $prompt);
            $section = trim((string) ($item['section_label'] ?? '')) ?: null;
            if ($section !== null && !isset($sectionPositions[$section])) {
                $sectionPositions[$section] = count($sectionPositions);
            }
            $q->setSectionLabel($section);
            $q->setSectionPosition($section !== null ? $sectionPositions[$section] : 0);
            $q->setPosition($pos++);
            $q->setType((string) ($item['type'] ?? 'numeric'));
            $q->setOptions(is_array($item['options'] ?? null) ? array_values($item['options']) : null);
            $q->setCorrectAnswer(isset($item['correct_answer']) && $item['correct_answer'] !== '' ? (string) $item['correct_answer'] : null);
            $q->setMarks((int) ($item['marks'] ?? 1));
            $this->em->persist($q);
            $total += $q->getMarks();
        }
        $worksheet->setTotalMarks(max(1, $total));
        $this->em->flush();
        $this->audit->log('worksheet.questions.set', $request->getAttribute('user'), 'Worksheet', (string) $worksheet->getId(), null, ['count' => $pos, 'total_marks' => $total]);

        return Json::write($response, ['ok' => true, 'count' => $pos, 'total_marks' => $total]);
    }

    /** GET /assessment/worksheets/{id}/submission — the current student's own submission. */
    public function mine(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);

        return Json::write($response, $submission?->toArray() ?? ['submission' => null]);
    }

    /** GET /assessment/worksheets/{id}/submissions — staff view of all submissions. */
    public function submissions(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $worksheet = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($worksheet === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $subs = $this->em->getRepository(WorksheetSubmission::class)->findBy(['worksheet' => $worksheet], ['submittedAt' => 'DESC']);
        $graded = count(array_filter($subs, static fn (WorksheetSubmission $s) => $s->getStatus() === WorksheetSubmission::GRADED));

        return Json::write($response, [
            'worksheet' => $worksheet->toArray(),
            'summary' => ['submissions' => count($subs), 'graded' => $graded, 'ungraded' => count($subs) - $graded],
            'data' => array_map(static fn (WorksheetSubmission $s) => $s->toArray(), $subs),
        ]);
    }

    /** POST /assessment/worksheet-submissions/{id}/grade — staff records score + feedback. */
    public function grade(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $submission = $this->em->getRepository(WorksheetSubmission::class)->find((int) $args['id']);
        if ($submission === null || !$this->canActWithin($request, $submission->getStudent()->getInstitution())) {
            return Json::error($response, 'Submission not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        if (!isset($body['score']) || !is_numeric($body['score'])) {
            return Json::error($response, 'A numeric score is required.', 422);
        }
        $score = (int) $body['score'];
        $max = $submission->getWorksheet()->getTotalMarks();
        if ($score < 0 || $score > $max) {
            return Json::error($response, "Score must be between 0 and {$max}.", 422);
        }
        $submission->setScore($score);
        $submission->setFeedback(isset($body['feedback']) && $body['feedback'] !== '' ? (string) $body['feedback'] : null);
        $submission->setStatus(WorksheetSubmission::GRADED);
        $submission->setGradedAt(new DateTimeImmutable());
        $this->notify->notify(
            $submission->getStudent(),
            'grade',
            'Worksheet graded: ' . $submission->getWorksheet()->getTitle(),
            'You scored ' . $score . '/' . $max . '.' . ($submission->getFeedback() ? ' ' . $submission->getFeedback() : ''),
            '/student/academics/worksheets',
        );
        $this->em->flush();
        $this->audit->log('worksheet.grade', $request->getAttribute('user'), 'WorksheetSubmission', (string) $submission->getId(), null, ['score' => $score]);

        return Json::write($response, $submission->toArray());
    }

    // --- solver helpers ---

    /**
     * Upsert the student's answers for the given questions; returns every
     * response now on the submission (existing + newly created).
     *
     * @param WorksheetQuestion[] $questions
     * @param array<int,mixed> $incoming
     * @return WorksheetResponse[]
     */
    private function upsertResponses(WorksheetSubmission $submission, array $questions, array $incoming): array
    {
        $byId = [];
        foreach ($questions as $q) {
            $byId[$q->getId()] = $q;
        }
        $existing = [];
        foreach ($this->responsesFor($submission) as $r) {
            $existing[$r->getQuestion()->getId()] = $r;
        }
        foreach ($incoming as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qid = (int) ($item['question_id'] ?? 0);
            if (!isset($byId[$qid])) {
                continue;
            }
            $raw = $item['answer'] ?? null;
            $answer = ($raw === null || $raw === '') ? null : (is_array($raw) ? (string) json_encode(array_values($raw)) : (string) $raw);
            $resp = $existing[$qid] ?? null;
            if ($resp === null) {
                $resp = new WorksheetResponse($submission, $byId[$qid]);
                $this->em->persist($resp);
                $existing[$qid] = $resp;
            }
            $resp->setAnswer($answer);
        }
        return array_values($existing);
    }

    /** @return WorksheetResponse[] */
    private function responsesFor(WorksheetSubmission $submission): array
    {
        if ($submission->getId() === null) {
            return [];
        }
        return $this->em->getRepository(WorksheetResponse::class)->findBy(['submission' => $submission]);
    }

    /** Auto-mark an objective answer. @return array{0: bool, 1: int} [correct, awardedMarks] */
    private function autoMark(WorksheetQuestion $q, ?string $answer): array
    {
        $ans = $this->norm($answer);
        if ($ans === '' || $q->getCorrectAnswer() === null) {
            return [false, 0];
        }
        $correct = $this->norm($q->getCorrectAnswer());
        $ok = false;
        switch ($q->getType()) {
            case 'numeric':
                $ok = is_numeric($ans) && is_numeric($correct) && abs((float) $ans - (float) $correct) < 1e-9;
                break;
            case 'true_false':
                $ok = $this->boolish($ans) === $this->boolish($correct);
                break;
            case 'short':
                foreach (explode('|', $correct) as $accepted) {
                    if (trim($accepted) === $ans) { $ok = true; break; }
                }
                break;
            case 'mcq':
            default:
                $ok = $ans === $correct;
                break;
        }
        return [$ok, $ok ? $q->getMarks() : 0];
    }

    private function norm(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    private function boolish(string $v): bool
    {
        return in_array($v, ['true', 't', 'yes', 'y', '1'], true);
    }

    /** Build the full solver payload for a worksheet + (optional) submission. */
    private function solvePayload(Worksheet $worksheet, ?WorksheetSubmission $submission): array
    {
        $questions = $this->em->getRepository(WorksheetQuestion::class)
            ->findBy(['worksheet' => $worksheet], ['sectionPosition' => 'ASC', 'position' => 'ASC']);

        $respMap = [];
        if ($submission !== null) {
            foreach ($this->responsesFor($submission) as $r) {
                $respMap[$r->getQuestion()->getId()] = $r->toArray();
            }
        }

        $sections = [];
        $order = [];
        $totalMarks = 0;
        $answered = 0;
        $marksObtained = 0;
        foreach ($questions as $q) {
            $totalMarks += $q->getMarks();
            $key = $q->getSectionPosition() . '::' . (string) $q->getSectionLabel();
            if (!isset($sections[$key])) {
                $sections[$key] = ['label' => $q->getSectionLabel(), 'position' => $q->getSectionPosition(), 'marks' => 0, 'questions' => []];
                $order[] = $key;
            }
            $sections[$key]['marks'] += $q->getMarks();
            $sections[$key]['questions'][] = $q->toLearnerArray();
            $r = $respMap[$q->getId()] ?? null;
            if ($r !== null && $r['answer'] !== null && $r['answer'] !== '') {
                $answered++;
            }
            if ($r !== null && $r['awarded_marks'] !== null) {
                $marksObtained += (int) $r['awarded_marks'];
            }
        }

        return [
            'worksheet' => $worksheet->toArray(),
            'sections' => array_map(static fn ($k) => $sections[$k], $order),
            'responses' => $respMap,
            'status' => $submission?->getStatus() ?? 'not_started',
            'score' => $submission?->getScore(),
            'feedback' => $submission?->getFeedback(),
            'submitted_at' => $submission?->getSubmittedAt()?->format(DATE_ATOM),
            'graded_at' => $submission?->getGradedAt()?->format(DATE_ATOM),
            'progress' => [
                'answered' => $answered,
                'total_questions' => count($questions),
                'total_marks' => $totalMarks,
                'marks_obtained' => $marksObtained,
            ],
        ];
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

    private function staffGuard(Request $request, Response $response): ?Response
    {
        $user = $this->currentUser($request);
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can do that.', 403);
        }
        return null;
    }
}
