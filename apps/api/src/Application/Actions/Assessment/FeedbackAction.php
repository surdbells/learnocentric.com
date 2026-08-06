<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\FeedbackNote;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Teacher feedback notes to students; students read and acknowledge them. */
final class FeedbackAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly \App\Service\NotificationService $notify,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            return $this->create($request, $response);
        }
        return $this->staffList($request, $response);
    }

    /** GET /assessment/feedback — staff list (filter by student_id / type). */
    private function staffList(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['created_at'], ['student_id', 'type', 'topic_id'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('f')->from(FeedbackNote::class, 'f')->join('f.student', 'st');
        if ($institution !== null) {
            $qb->andWhere('st.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(f.message) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['student_id'])) {
            $qb->andWhere('f.student = :sid')->setParameter('sid', (int) $query->filters['student_id']);
        }
        if (isset($query->filters['type'])) {
            $qb->andWhere('f.type = :ty')->setParameter('ty', $query->filters['type']);
        }
        if (isset($query->filters['topic_id'])) {
            $qb->andWhere('f.topic = :tid')->setParameter('tid', (int) $query->filters['topic_id']);
        }

        $mapper = static fn (FeedbackNote $f) => $f->toArray();
        if (!$query->paginated) {
            $qb->orderBy('f.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        return Json::write($response, Paginator::paginate($qb, 'f', $query, ['created_at' => 'f.createdAt'], $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $author = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $student = $this->em->getRepository(User::class)->find((int) ($body['student_id'] ?? 0));
        $message = trim((string) ($body['message'] ?? ''));
        if ($student === null || $student->getRole()->getCode() !== 'student') {
            return Json::error($response, 'A valid student is required.', 422);
        }
        if ($message === '') {
            return Json::error($response, 'A feedback message is required.', 422);
        }
        // Same-institution guard (super admins may target anyone).
        if ($author->getInstitution() !== null
            && $student->getInstitution()?->getId() !== $author->getInstitution()->getId()) {
            return Json::error($response, 'You can only send feedback to students in your institution.', 403);
        }

        $note = new FeedbackNote($student, $message);
        $note->setAuthor($author);
        $note->setType((string) ($body['type'] ?? 'general'));
        if (!empty($body['topic_id'])) {
            $note->setTopic($this->em->getRepository(Topic::class)->find((int) $body['topic_id']));
        }
        // Structured fields for parent reports (spec §7.5, §18) — all optional.
        if (array_key_exists('strengths', $body)) {
            $note->setStrengths(trim((string) $body['strengths']));
        }
        if (array_key_exists('practice_needed', $body)) {
            $note->setPracticeNeeded(trim((string) $body['practice_needed']));
        }
        if (array_key_exists('parent_support_suggestion', $body)) {
            $note->setParentSupportSuggestion(trim((string) $body['parent_support_suggestion']));
        }
        // Structured breakdown (design: Feedback_LD).
        if (isset($body['score']) && is_numeric($body['score'])) {
            $note->setScore((int) $body['score']);
        }
        if (array_key_exists('common_error', $body)) {
            $note->setCommonError(trim((string) $body['common_error']));
        }
        if (array_key_exists('next_step', $body)) {
            $note->setNextStep(trim((string) $body['next_step']));
        }
        if (array_key_exists('source_type', $body)) {
            $note->setSourceType(trim((string) $body['source_type']));
        }
        if (array_key_exists('source_title', $body)) {
            $note->setSourceTitle(trim((string) $body['source_title']));
        }
        if (array_key_exists('subject', $body)) {
            $note->setSubjectName(trim((string) $body['subject']));
        }
        if (array_key_exists('attachment_url', $body)) {
            $note->setAttachmentUrl(trim((string) $body['attachment_url']) ?: null);
        }
        if (is_array($body['focus_areas'] ?? null)) {
            $areas = [];
            foreach ($body['focus_areas'] as $fa) {
                $label = trim((string) ($fa['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $areas[] = ['label' => $label, 'score' => max(0, min(100, (int) ($fa['score'] ?? 0)))];
            }
            $note->setFocusAreas($areas);
        }
        $this->em->persist($note);
        $this->notify->notify(
            $student,
            'feedback',
            'New feedback from ' . $author->getFirstName() . ' ' . $author->getLastName(),
            $note->getMessage(),
            '/student/academics/feedback',
        );
        $this->em->flush();
        $this->audit->log('feedback.send', $author, 'FeedbackNote', (string) $note->getId(), null, ['student_id' => $student->getId(), 'type' => $note->getType()]);

        return Json::write($response, $note->toArray(), 201);
    }

    /** GET /assessment/feedback/mine — the current student's feedback inbox + summary. */
    public function mine(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $notes = $this->em->getRepository(FeedbackNote::class)->findBy(['student' => $student], ['createdAt' => 'DESC']);
        $unread = count(array_filter($notes, static fn (FeedbackNote $n) => !$n->isAcknowledged()));
        $reviewed = count($notes) - $unread;

        // Average performance from scored feedback.
        $scores = array_values(array_filter(array_map(static fn (FeedbackNote $n) => $n->getScore(), $notes), static fn ($s) => $s !== null));
        $avgPerformance = $scores === [] ? null : (int) round(array_sum($scores) / count($scores));

        // Aggregate teacher-rated focus areas across all feedback.
        $sum = [];
        $cnt = [];
        foreach ($notes as $n) {
            foreach ($n->getFocusAreas() ?? [] as $fa) {
                $label = (string) ($fa['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $sum[$label] = ($sum[$label] ?? 0) + (int) ($fa['score'] ?? 0);
                $cnt[$label] = ($cnt[$label] ?? 0) + 1;
            }
        }
        $focusAreas = [];
        foreach ($sum as $label => $total) {
            $focusAreas[] = ['label' => $label, 'score' => (int) round($total / $cnt[$label])];
        }

        return Json::write($response, [
            'data' => array_map(static fn (FeedbackNote $n) => $n->toArray(), $notes),
            'meta' => [
                'total' => count($notes),
                'unread' => $unread,
                'reviewed' => $reviewed,
                'reviewed_pct' => count($notes) ? (int) round($reviewed / count($notes) * 100) : 0,
                'avg_performance' => $avgPerformance,
                'focus_areas' => $focusAreas,
            ],
        ]);
    }

    /** POST /assessment/feedback/{id}/acknowledge — student marks a note read. */
    public function acknowledge(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $note = $this->em->getRepository(FeedbackNote::class)->find((int) $args['id']);
        if ($note === null) {
            return Json::error($response, 'Note not found.', 404);
        }
        if ($note->getStudent()->getId() !== $student->getId()) {
            return Json::error($response, 'This note is not addressed to you.', 403);
        }
        $note->setAcknowledged(true);
        $note->setAcknowledgedAt(new DateTimeImmutable());
        $this->em->flush();

        return Json::write($response, $note->toArray());
    }

    // --- helpers ---

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
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
