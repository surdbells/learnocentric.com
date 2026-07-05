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
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/assessment/assessments — build quizzes/tests/worksheets from
 * validated questions. Enforces the answer gate on attach and on publish.
 */
final class AssessmentsAction
{
    use ResolvesInstitution;

    private const EDITABLE = [Lifecycle::DRAFT, Lifecycle::REVIEW];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
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
            ['subject_id', 'class_id', 'type', 'track', 'approval_status'],
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

        $mapper = fn (Assessment $a) => $this->row($a);

        if (!$query->paginated) {
            $qb->orderBy('a.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['title' => 'a.title', 'type' => 'a.type', 'approval_status' => 'a.approvalStatus', 'created_at' => 'a.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'a', $query, $sortMap, $mapper));
    }

    /** GET /assessment/assessments/{id} — full detail with ordered questions. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $a = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }

        return Json::write($response, $this->row($a, true));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? 0));
        if ($title === '' || $subject === null) {
            return Json::error($response, 'A title and a valid subject_id are required.', 422);
        }

        $a = new Assessment($subject, $title);
        $a->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($a, $body);
        $this->em->persist($a);
        $this->em->flush();
        $this->audit->log('assessment.create', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, $a->toArray());

        return Json::write($response, $this->row($a), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $a = $this->em->getRepository(Assessment::class)->find((int) ($body['id'] ?? 0));
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This assessment is {$a->getApprovalStatus()}; return it to draft before editing.", 409);
        }
        $before = $a->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $a->setTitle((string) $body['title']);
        }
        $this->applyFields($a, $body);
        $this->em->flush();
        $this->audit->log('assessment.update', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), $before, $a->toArray());

        return Json::write($response, $this->row($a));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $a = $this->em->getRepository(Assessment::class)->find($id);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        $this->em->remove($a);
        $this->em->flush();
        $this->audit->log('assessment.delete', $request->getAttribute('user'), 'Assessment', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /assessment/assessments/{id}/questions — attach a validated question. */
    public function attach(Request $request, Response $response, array $args): Response
    {
        $a = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This assessment is {$a->getApprovalStatus()}; return it to draft to change its questions.", 409);
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

        return Json::write($response, $this->row($a, true), 201);
    }

    /** DELETE /assessment/assessments/{id}/questions/{qid} — detach a question. */
    public function detach(Request $request, Response $response, array $args): Response
    {
        $a = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        if (!in_array($a->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This assessment is {$a->getApprovalStatus()}; return it to draft to change its questions.", 409);
        }
        $qid = (int) $args['qid'];
        foreach ($a->getItems() as $item) {
            if ($item->getQuestion()->getId() === $qid) {
                $this->em->remove($item);
                $this->em->flush();
                $this->audit->log('assessment.detach_question', $request->getAttribute('user'), 'Assessment', (string) $a->getId(), null, ['question_id' => $qid]);
                return Json::write($response, $this->row($a, true));
            }
        }

        return Json::error($response, 'That question is not in this assessment.', 404);
    }

    /** POST /assessment/assessments/{id}/transition — lifecycle, gated on all questions being validated. */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $a = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        $to = (string) ($body['to'] ?? '');

        if (in_array($to, [Lifecycle::APPROVED, Lifecycle::PUBLISHED], true)) {
            if ($a->getItems()->count() === 0) {
                return Json::error($response, 'Add at least one question before approving or publishing.', 422);
            }
            $bad = $a->unvalidatedQuestions();
            if (!empty($bad)) {
                return Json::error($response, 'Every question must pass answer validation first. Unvalidated: ' . implode(', ', $bad), 422);
            }
        }

        try {
            $result = $this->lifecycle->transition($a, $to, $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['assessment' => $this->row($a, true)] + $result);
    }

    /** GET /assessment/assessments/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $a = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($a === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($a)));
    }

    /** POST /assessment/assessments/bulk-delete — body { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Assessment::class)->findBy(['id' => $ids]) as $a) {
            $this->em->remove($a);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('assessment.bulk_delete', $request->getAttribute('user'), 'Assessment', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
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

    private function row(Assessment $a, bool $withItems = false): array
    {
        return $a->toArray($withItems) + [
            'next_states' => Lifecycle::nextStates($a->getApprovalStatus()),
            'unvalidated_questions' => $a->unvalidatedQuestions(),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'Assessment', 'entityId' => $a->getId()]),
        ];
    }
}
