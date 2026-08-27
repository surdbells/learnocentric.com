<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Question;
use App\Domain\Entity\Topic;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/assessment/questions, the question bank. CRUD plus the
 * answer-validation gate (/validate) and the shared lifecycle (/transition).
 */
final class QuestionsAction
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
            ['stem', 'difficulty', 'type', 'approval_status', 'created_at'],
            ['topic_id', 'subject_id', 'type', 'track', 'difficulty', 'approval_status', 'answer_validated'],
            'created_at'
        );

        $qb = $this->em->createQueryBuilder()->select('qn')->from(Question::class, 'qn')
            ->join('qn.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(qn.stem) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        foreach (['type' => 'qn.type', 'track' => 'qn.track', 'difficulty' => 'qn.difficulty', 'approval_status' => 'qn.approvalStatus'] as $key => $field) {
            if (isset($query->filters[$key])) {
                $qb->andWhere("$field = :$key")->setParameter($key, $query->filters[$key]);
            }
        }
        if (isset($query->filters['topic_id'])) {
            $qb->andWhere('qn.topic = :tid')->setParameter('tid', (int) $query->filters['topic_id']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['answer_validated'])) {
            $qb->andWhere('qn.answerValidated = :av')->setParameter('av', (bool) (int) $query->filters['answer_validated']);
        }

        $mapper = fn (Question $q) => $this->row($q);

        if (!$query->paginated) {
            $qb->orderBy('qn.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['stem' => 'qn.stem', 'difficulty' => 'qn.difficulty', 'type' => 'qn.type', 'approval_status' => 'qn.approvalStatus', 'created_at' => 'qn.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'qn', $query, $sortMap, $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $stem = trim((string) ($body['stem'] ?? ''));
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['topic_id'] ?? 0));
        if ($stem === '' || $topic === null) {
            return Json::error($response, 'A question stem and a valid topic_id are required.', 422);
        }

        $q = new Question($topic, $stem);
        $q->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($q, $body);
        $this->em->persist($q);
        $this->em->flush();
        $this->audit->log('question.create', $request->getAttribute('user'), 'Question', (string) $q->getId(), null, $q->toArray());

        return Json::write($response, $this->row($q), 201);
    }

    /**
     * POST /assessment/questions/bulk, import many questions at once. Body:
     * { topic_id?: int, questions: [{ stem, type, options?, correct_answer?,
     * marks?, difficulty?, explanation?, misconception_tag?, topic_id? }, ...] }.
     * Each row is created as a draft; malformed rows are reported, not fatal.
     */
    public function bulkCreate(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $defaultTopicId = (int) ($body['topic_id'] ?? 0);
        $items = $body['questions'] ?? null;
        if (!is_array($items) || $items === []) {
            return Json::error($response, 'Provide a non-empty "questions" array.', 422);
        }
        if (count($items) > 500) {
            return Json::error($response, 'You can import at most 500 questions at a time.', 422);
        }

        $created = 0;
        $errors = [];
        $topicCache = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                $errors[] = ['row' => $i + 1, 'error' => 'Not an object.'];
                continue;
            }
            $stem = trim((string) ($item['stem'] ?? ''));
            $topicId = (int) ($item['topic_id'] ?? $defaultTopicId);
            if ($stem === '') {
                $errors[] = ['row' => $i + 1, 'error' => 'Missing question stem.'];
                continue;
            }
            if (!isset($topicCache[$topicId])) {
                $topicCache[$topicId] = $topicId > 0 ? $this->em->getRepository(Topic::class)->find($topicId) : null;
            }
            $topic = $topicCache[$topicId];
            if ($topic === null) {
                $errors[] = ['row' => $i + 1, 'error' => 'Unknown topic_id ' . $topicId . '.'];
                continue;
            }
            $q = new Question($topic, $stem);
            $q->setApprovalStatus(Lifecycle::DRAFT);
            $this->applyFields($q, $item);
            $this->em->persist($q);
            $created++;
        }
        if ($created > 0) {
            $this->em->flush();
            $this->audit->log('question.bulk_create', $request->getAttribute('user'), 'Question', 'bulk', null, ['created' => $created, 'errors' => count($errors)]);
        }

        return Json::write($response, ['created' => $created, 'total' => count($items), 'errors' => $errors], 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $q = $this->em->getRepository(Question::class)->find((int) ($body['id'] ?? 0));
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        if (!in_array($q->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This question is {$q->getApprovalStatus()}; return it to draft before editing.", 409);
        }
        $before = $q->toArray();
        if (isset($body['stem']) && trim((string) $body['stem']) !== '') {
            $q->setStem((string) $body['stem']);
        }
        $this->applyFields($q, $body);
        // Any content change invalidates a prior answer validation, must re-check.
        $q->setAnswerValidated(false);
        $this->em->flush();
        $this->audit->log('question.update', $request->getAttribute('user'), 'Question', (string) $q->getId(), $before, $q->toArray());

        return Json::write($response, $this->row($q));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $q = $this->em->getRepository(Question::class)->find($id);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        $before = $q->toArray();
        $this->em->remove($q);
        $this->em->flush();
        $this->audit->log('question.delete', $request->getAttribute('user'), 'Question', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /assessment/questions/{id}/validate, run the answer-validation gate. */
    public function validate(Request $request, Response $response, array $args): Response
    {
        $q = $this->em->getRepository(Question::class)->find((int) $args['id']);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        $error = $q->answerError();
        if ($error !== null) {
            $q->setAnswerValidated(false);
            $this->em->flush();
            return Json::error($response, $error, 422);
        }
        $q->setAnswerValidated(true);
        $this->em->flush();
        $this->audit->log('question.validate', $request->getAttribute('user'), 'Question', (string) $q->getId(), null, ['answer_validated' => true]);

        return Json::write($response, $this->row($q));
    }

    /** POST /assessment/questions/{id}/transition, lifecycle, gated on validation for publish. */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $q = $this->em->getRepository(Question::class)->find((int) $args['id']);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        $to = (string) ($body['to'] ?? '');

        // The gate: a question cannot be approved or published without a validated
        // answer, an explanation (every type is auto-marked) and a difficulty set.
        if (in_array($to, [Lifecycle::APPROVED, Lifecycle::PUBLISHED], true)) {
            $error = $q->answerError();
            if ($error !== null) {
                return Json::error($response, $error, 422);
            }
            $missing = [];
            if (!$q->isAnswerValidated()) {
                $missing[] = 'a validated answer';
            }
            if (trim((string) $q->getExplanation()) === '') {
                $missing[] = 'an explanation';
            }
            if (!in_array($q->getDifficulty(), Question::DIFFICULTIES, true)) {
                $missing[] = 'a difficulty level';
            }
            if (!empty($missing)) {
                return Json::error($response, 'This question needs ' . implode(', ', $missing) . ' before it can be approved or published.', 422);
            }
        }

        try {
            $result = $this->lifecycle->transition($q, $to, $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['question' => $this->row($q)] + $result);
    }

    /** GET /assessment/questions/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $q = $this->em->getRepository(Question::class)->find((int) $args['id']);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($q)));
    }

    /** POST /assessment/questions/bulk-delete, body { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Question::class)->findBy(['id' => $ids]) as $q) {
            $this->em->remove($q);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('question.bulk_delete', $request->getAttribute('user'), 'Question', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    private function applyFields(Question $q, array $body): void
    {
        if (isset($body['type'])) { $q->setType((string) $body['type']); }
        if (isset($body['track'])) { $q->setTrack((string) $body['track']); }
        if (isset($body['difficulty'])) { $q->setDifficulty((string) $body['difficulty']); }
        if (array_key_exists('options', $body)) { $q->setOptions(is_array($body['options']) ? $body['options'] : null); }
        if (array_key_exists('correct_answer', $body)) {
            $answer = $body['correct_answer'];
            // multi-select stores a de-duplicated, re-indexed list of option keys.
            if ($q->getType() === 'multi') {
                $answer = is_array($answer) ? array_values(array_unique(array_map('strval', $answer))) : [];
            }
            $q->setCorrectAnswer($answer);
        }
        if (array_key_exists('explanation', $body)) { $q->setExplanation($body['explanation'] !== '' ? (string) $body['explanation'] : null); }
        if (array_key_exists('misconception_tag', $body)) { $q->setMisconceptionTag($body['misconception_tag'] !== '' && $body['misconception_tag'] !== null ? (string) $body['misconception_tag'] : null); }
        if (isset($body['marks'])) { $q->setMarks((int) $body['marks']); }
    }

    private function row(Question $q): array
    {
        return $q->toArray() + [
            'next_states' => Lifecycle::nextStates($q->getApprovalStatus()),
            'answer_error' => $q->answerError(),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'Question', 'entityId' => $q->getId()]),
        ];
    }
}
