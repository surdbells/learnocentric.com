<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
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
            $rows[] = $worksheet->toArray() + ['submission' => $submission?->toArray()];
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
        $text = trim((string) ($body['response_text'] ?? ''));
        $attachment = trim((string) ($body['attachment_url'] ?? ''));
        if ($text === '' && $attachment === '') {
            return Json::error($response, 'Add a written response or attach a file before submitting.', 422);
        }

        $submission = $this->em->getRepository(WorksheetSubmission::class)->findOneBy(['worksheet' => $worksheet, 'student' => $student]);
        if ($submission === null) {
            $submission = new WorksheetSubmission($worksheet, $student);
            $this->em->persist($submission);
        } elseif ($submission->getStatus() === WorksheetSubmission::GRADED) {
            return Json::error($response, 'This worksheet has already been graded and cannot be resubmitted.', 409);
        }
        $submission->setResponseText($text !== '' ? $text : null);
        $submission->setAttachmentUrl($attachment !== '' ? $attachment : null);
        $submission->setStatus(WorksheetSubmission::SUBMITTED);
        $submission->setSubmittedAt(new DateTimeImmutable());
        $this->em->flush();
        $this->audit->log('worksheet.submit', $student, 'WorksheetSubmission', (string) $submission->getId(), null, ['worksheet_id' => $worksheet->getId()]);

        return Json::write($response, $submission->toArray());
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
        if ($submission === null) {
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
