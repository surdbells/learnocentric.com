<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentQuestion;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Question;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Term;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use App\Service\SubmittedWork;
use App\Service\LifecycleNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/assessment/assessments, build quizzes/tests/worksheets from
 * validated questions. Enforces the answer gate on attach and on publish.
 */
final class AssessmentsAction
{
    use ResolvesInstitution;

    private const EDITABLE = LifecycleService::EDITABLE;
    /** Roles allowed to set an assessment's moderation status. */
    private const MODERATORS = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleNotifier $notifier,
        private readonly SubmittedWork $work,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest(
            $request,
            ['title', 'type', 'approval_status', 'created_at'],
            ['subject_id', 'class_id', 'type', 'track', 'approval_status', 'term_id'],
            'created_at'
        );

        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(a.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        foreach (['type' => 'a.type', 'track' => 'a.track', 'approval_status' => 'a.approvalStatus'] as $key => $field) {
            if (isset($query->filters[$key])) {
                $qb->andWhere("$field = :$key")->setParameter($key, $query->filters[$key]);
            }
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('a.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('a.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }
        if (isset($query->filters['term_id'])) {
            $qb->andWhere('(a.term = :tid OR a.term IS NULL)')->setParameter('tid', (int) $query->filters['term_id']);
        }

        $mapper = fn (Assessment $a) => $this->row($a, $request);

        if (!$query->paginated) {
            $qb->orderBy('a.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['title' => 'a.title', 'type' => 'a.type', 'approval_status' => 'a.approvalStatus', 'created_at' => 'a.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'a', $query, $sortMap, $mapper));
    }

    /** GET /assessment/assessments/{id}, full detail with ordered questions. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }

        return Json::write($response, $this->row($a, $request, true));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? 0));
        if ($title === '' || $subject === null || !$this->canActWithin($request, $subject->getInstitution())) {
            return Json::error($response, 'A title and a valid subject_id are required.', 422);
        }

        $a = new Assessment($subject, $title);
        $a->setApprovalStatus(Lifecycle::DRAFT);
        // New assessments start from the school's pass mark (Settings → Academic
        // Policies) unless the author sets one.
        $schoolPass = $subject->getInstitution()->getSettings()['grading']['pass_mark'] ?? null;
        if (!isset($body['pass_mark']) && is_numeric($schoolPass)) {
            $a->setPassMark(max(0, min(100, (int) $schoolPass)));
        }
        $this->applyFields($a, $body);
        $this->em->persist($a);
        $this->em->flush();
        $this->audit->log('assessment.create', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, $a->toArray());

        return Json::write($response, $this->row($a, $request), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $a = $this->find($request, (int) ($body['id'] ?? 0));
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReason($a, $request), 409);
        }
        $before = $a->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $a->setTitle((string) $body['title']);
        }
        $this->applyFields($a, $body);
        $this->em->flush();
        $this->audit->log('assessment.update', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), $before, $a->toArray());

        return Json::write($response, $this->row($a, $request));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $a = $this->find($request, $id);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        // Work learners have handed in is never deleted along with its parent.
        if (($held = $this->work->refusal($a)) !== null) {
            return Json::error($response, $held, 409);
        }
        $this->em->remove($a);
        $this->em->flush();
        $this->audit->log('assessment.delete', $request->getAttribute('user'), 'Assessment', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /assessment/assessments/{id}/questions, attach a validated question. */
    public function attach(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReason($a, $request), 409);
        }
        $body = (array) $request->getParsedBody();
        $question = $this->em->getRepository(Question::class)->find((int) ($body['question_id'] ?? 0));
        if ($question === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        if ($question->getTopic()->getSubject()->getId() !== $a->getSubject()->getId()) {
            return Json::error($response, 'The question belongs to a different subject.', 422);
        }
        // The gate: only answer-validated questions can join an assessment.
        if (!$question->isAnswerValidated()) {
            return Json::error($response, 'This question has not passed answer validation and cannot be added.', 422);
        }
        foreach ($a->getItems() as $item) {
            if ($item->getQuestion()->getId() === $question->getId()) {
                return Json::error($response, 'That question is already in this assessment.', 409);
            }
        }

        $link = new AssessmentQuestion($a, $question, $a->getItems()->count());
        if (isset($body['marks'])) {
            $link->setMarks((int) $body['marks']);
        }
        $this->em->persist($link);
        $a->getItems()->add($link); // keep the in-memory collection in sync for the response
        $this->em->flush();
        $this->audit->log('assessment.attach_question', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, ['question_id' => $question->getId()]);

        return Json::write($response, $this->row($a, $request, true), 201);
    }

    /**
     * POST /assessment/assessments/{id}/draw-from-bank, bulk-attach validated
     * questions from the bank instead of picking them one by one. Draws from the
     * assessment's topic (or its whole subject when no topic is set); with an
     * optional {count} it draws that many at random, otherwise it takes them all.
     * Only answer-validated questions qualify (same gate as attach); already-
     * attached questions are skipped.
     */
    public function drawFromBank(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReason($a, $request), 409);
        }

        $topic = $a->getTopic();
        if ($topic !== null) {
            $pool = $this->em->getRepository(Question::class)->findBy(['topic' => $topic]);
        } else {
            $pool = $this->em->createQueryBuilder()->select('q')->from(Question::class, 'q')->join('q.topic', 't')
                ->where('t.subject = :s')->setParameter('s', $a->getSubject())->getQuery()->getResult();
        }

        $attached = [];
        foreach ($a->getItems() as $item) {
            $attached[$item->getQuestion()->getId()] = true;
        }
        $candidates = array_values(array_filter(
            $pool,
            static fn (Question $q) => $q->isAnswerValidated() && !isset($attached[$q->getId()])
        ));

        $count = (int) (((array) $request->getParsedBody())['count'] ?? 0);
        if ($count > 0 && count($candidates) > $count) {
            shuffle($candidates);
            $candidates = array_slice($candidates, 0, $count);
        }

        $order = $a->getItems()->count();
        foreach ($candidates as $q) {
            $link = new AssessmentQuestion($a, $q, $order++);
            $this->em->persist($link);
            $a->getItems()->add($link);
        }
        $this->em->flush();
        $this->audit->log('assessment.draw_from_bank', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, ['drawn' => count($candidates)]);

        return Json::write($response, ['drawn' => count($candidates)] + $this->row($a, $request, true), 201);
    }

    /** DELETE /assessment/assessments/{id}/questions/{qid}, detach a question. */
    public function detach(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReason($a, $request), 409);
        }
        $qid = (int) $args['qid'];
        foreach ($a->getItems() as $item) {
            if ($item->getQuestion()->getId() === $qid) {
                $this->em->remove($item);
                $this->em->flush();
                $this->audit->log('assessment.detach_question', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, ['question_id' => $qid]);
                return Json::write($response, $this->row($a, $request, true));
            }
        }

        return Json::error($response, 'That question is not in this assessment.', 404);
    }

    /** POST /assessment/assessments/{id}/transition, lifecycle, gated on all questions being validated. */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        $to = (string) ($body['to'] ?? '');

        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $from = $a->getApprovalStatus();

        // The gate applies to any move that ends with learners seeing the
        // assessment, including "submit" at a school with no review step.
        if ($this->lifecycle->leadsToPublication($from, $to, $actor)) {
            $blockers = $this->blockers($a);
            if ($blockers !== []) {
                return Json::error($response, $blockers[0], 422);
            }
        }

        try {
            $result = $this->lifecycle->transition($a, $to, $actor, $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }
        $this->notifier->announce(
            $a,
            'assessment',
            $a->getTitle(),
            $a->getSubject()->getInstitution(),
            $from,
            (string) $result['status'],
            $actor,
            isset($body['note']) ? (string) $body['note'] : null,
            '/academics/assessments',
        );
        $this->em->flush();

        return Json::write($response, ['assessment' => $this->row($a, $request, true)] + $result);
    }

    /** POST /assessment/assessments/{id}/moderate, set the moderation status (staff/approver only). */
    public function moderate(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->effectiveCode(), self::MODERATORS, true)) {
            return Json::error($response, 'You are not allowed to moderate assessments.', 403);
        }
        $body = (array) $request->getParsedBody();
        $status = (string) ($body['moderation_status'] ?? '');
        if (!in_array($status, Assessment::MODERATION_STATUSES, true)) {
            return Json::error($response, 'Invalid moderation status. Use one of: ' . implode(', ', Assessment::MODERATION_STATUSES) . '.', 422);
        }
        $before = $a->toArray();
        $a->setModerationStatus($status);
        $this->em->flush();
        $this->audit->log('assessment.moderate', $user, 'Assessment', (string) $a->getId(), $before, $a->toArray());

        return Json::write($response, $this->row($a, $request, true));
    }

    /** GET /assessment/assessments/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $a = $this->find($request, (int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($a)));
    }

    /** POST /assessment/assessments/bulk-delete, body { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        $kept = 0;
        foreach ($this->em->getRepository(Assessment::class)->findBy(['id' => $ids]) as $a) {
            if (!$this->canActWithin($request, $a->getSubject()->getInstitution())) {
                continue; // another school's record
            }
            if ($this->work->refusal($a) !== null) {
                $kept++; // holds work learners have handed in
                continue;
            }
            $this->em->remove($a);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('assessment.bulk_delete', $request->getAttribute('user'), 'Assessment', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, [
            'deleted' => $count,
            'kept' => $kept,
            'message' => $kept > 0
                ? sprintf('%d of the selected assessments could not be deleted because learners have handed in work for %s. Archive %s instead.', $kept, $kept === 1 ? 'it' : 'them', $kept === 1 ? 'it' : 'them')
                : null,
        ]);
    }

    private function applyFields(Assessment $a, array $body): void
    {
        if (isset($body['type'])) { $a->setType((string) $body['type']); }
        if (isset($body['track'])) { $a->setTrack((string) $body['track']); }
        if (array_key_exists('duration_minutes', $body)) { $a->setDurationMinutes($body['duration_minutes'] !== '' && $body['duration_minutes'] !== null ? (int) $body['duration_minutes'] : null); }
        if (isset($body['pass_mark'])) { $a->setPassMark((int) $body['pass_mark']); }
        if (array_key_exists('instructions', $body)) { $a->setInstructions($body['instructions'] !== '' ? (string) $body['instructions'] : null); }
        if (array_key_exists('topic_id', $body)) {
            $a->setTopic(!empty($body['topic_id']) ? $this->em->getRepository(Topic::class)->find((int) $body['topic_id']) : null);
        }
        if (array_key_exists('class_id', $body)) {
            $a->setSchoolClass(!empty($body['class_id']) ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null);
        }
        if (array_key_exists('term_id', $body)) {
            $a->setTerm(!empty($body['term_id']) ? $this->em->getRepository(Term::class)->find((int) $body['term_id']) : null);
        }
    }

    /** Load an assessment the caller's school owns (another school's id reads as "not found"). */
    private function find(Request $request, int $id): ?Assessment
    {
        $a = $id > 0 ? $this->em->getRepository(Assessment::class)->find($id) : null;

        return $a !== null && $this->canActWithin($request, $a->getSubject()->getInstitution()) ? $a : null;
    }

    /** @return string[] what stops this assessment being approved or published */
    private function blockers(Assessment $a): array
    {
        return $a->publishBlockers();
    }

    /** Why the caller cannot change this assessment right now, and what would unlock it. */
    private function lockedReason(Assessment $a, Request $request): string
    {
        $g = $this->lifecycle->guidance($a, $request->getAttribute('user'), 'assessment', $a->getSubject()->getInstitution(), [], 'assessment');

        return $g['edit_reason'] ?? 'This assessment cannot be changed right now.';
    }

    private function row(Assessment $a, Request $request, bool $withItems = false): array
    {
        $data = $a->toArray($withItems);
        if ($withItems) {
            // Say why a question fails the answer gate, and whether it can still be corrected.
            $byId = [];
            foreach ($a->getItems() as $item) {
                $byId[$item->getQuestion()->getId()] = $item->getQuestion();
            }
            foreach ($data['questions'] as $i => $q) {
                $question = $byId[$q['question_id']] ?? null;
                if ($question === null || $question->isAnswerValidated()) {
                    continue;
                }
                $data['questions'][$i]['answer_error'] = $question->answerError();
                $data['questions'][$i]['question_editable'] = in_array($question->getApprovalStatus(), self::EDITABLE, true);
                $data['questions'][$i]['question'] = $question->toArray();
            }
        }
        /** @var User|null $viewer */
        $viewer = $request->getAttribute('user');

        return $data + [
            'next_states' => Lifecycle::nextStates($a->getApprovalStatus()),
            'unvalidated_questions' => $a->unvalidatedQuestions(),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'Assessment', 'entityId' => $a->getId()]),
            'lifecycle' => $viewer === null ? null
                : $this->lifecycle->guidance($a, $viewer, 'assessment', $a->getSubject()->getInstitution(), $this->blockers($a), 'assessment'),
        ];
    }
}
