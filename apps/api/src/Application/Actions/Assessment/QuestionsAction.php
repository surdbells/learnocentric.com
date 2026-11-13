<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\GovernedContent;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\Question;
use App\Domain\Entity\Topic;
use App\Domain\Entity\Institution;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleNotifier;
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
    use GovernedContent;

    private const EDITABLE = [Lifecycle::DRAFT, Lifecycle::REVIEW];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleNotifier $notifier,
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

        $mapper = fn (Question $q) => $this->row($q, $request);

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
        if ($stem === '' || $topic === null || !$this->canActWithin($request, $topic->getSubject()->getInstitution())) {
            return Json::error($response, 'A question stem and a valid topic_id are required.', 422);
        }

        $q = new Question($topic, $stem);
        $q->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($q, $body);
        $this->em->persist($q);
        $this->em->flush();
        $this->audit->log('question.create', $request->getAttribute('user'), 'Question', (string) $q->getId(), null, $q->toArray());

        return Json::write($response, $this->row($q, $request), 201);
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
                $found = $topicId > 0 ? $this->em->getRepository(Topic::class)->find($topicId) : null;
                $topicCache[$topicId] = $found !== null && $this->canActWithin($request, $found->getSubject()->getInstitution()) ? $found : null;
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
        $q = $this->findOwned($request, (int) ($body['id'] ?? 0));
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        if (!in_array($q->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReasonFor($request, $q, 'assessment', $this->ownerOf($q), 'question'), 409);
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

        return Json::write($response, $this->row($q, $request));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $q = $this->findOwned($request, $id);
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
        $q = $this->findOwned($request, (int) $args['id']);
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

        return Json::write($response, $this->row($q, $request));
    }

    /** POST /assessment/questions/{id}/transition, lifecycle, gated on validation for publish. */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $q = $this->findOwned($request, (int) $args['id']);
        if ($q === null) {
            return Json::error($response, 'Question not found.', 404);
        }
        // The gate (see blockers()) applies to any move that ends with the question
        // published, including "submit" at a school with no review step.
        [$result, $error] = $this->moveItem(
            $request,
            $q,
            'question',
            $q->getStem(),
            $this->ownerOf($q),
            '/academics/question-bank',
            fn (): array => $this->blockers($q),
        );
        if ($result === null) {
            return Json::error($response, (string) $error, 422);
        }

        return Json::write($response, ['question' => $this->row($q, $request)] + $result);
    }

    /** GET /assessment/questions/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $q = $this->findOwned($request, (int) $args['id']);
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
            if (!$this->canActWithin($request, $this->ownerOf($q))) {
                continue; // another school's record
            }
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
        if (array_key_exists('options', $body)) { $q->setOptions($this->normaliseOptions($body['options'])); }
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
        if (array_key_exists('common_wrong_answer', $body)) { $q->setCommonWrongAnswer($body['common_wrong_answer'] !== '' && $body['common_wrong_answer'] !== null ? (string) $body['common_wrong_answer'] : null); }
        if (isset($body['marks'])) { $q->setMarks((int) $body['marks']); }
    }

    /**
     * Options are stored as [{key, text}]. Imports and other clients may send a
     * plain list of texts or a {a: text, b: text} map, so accept those too rather
     * than saving choices with no text. Blank choices are dropped.
     *
     * @return array<int, array{key: string, text: string}>|null
     */
    private function normaliseOptions(mixed $options): ?array
    {
        if (!is_array($options) || $options === []) {
            return null;
        }
        $letters = 'abcdefghij';
        $out = [];
        $position = 0;
        foreach ($options as $k => $o) {
            if (is_array($o)) {
                $text = $o['text'] ?? $o['label'] ?? $o['value'] ?? '';
                $key = $o['key'] ?? null;
            } else {
                $text = $o;
                $key = is_string($k) ? $k : null;
            }
            $text = trim((string) (is_scalar($text) ? $text : ''));
            $key = strtolower(trim((string) ($key ?? '')));
            if ($key === '') {
                $key = $letters[$position] ?? (string) ($position + 1);
            }
            $position++;
            if ($text !== '') {
                $out[] = ['key' => $key, 'text' => $text];
            }
        }

        return $out === [] ? null : $out;
    }

    /** @return string[] what stops this question being approved or published */
    private function blockers(Question $q): array
    {
        return $q->publishBlockers();
    }

    /** The school a question belongs to. */
    private function ownerOf(Question $item): ?Institution
    {
        return $item->getTopic()->getSubject()->getInstitution();
    }

    /** A question the caller's school owns (another school's id reads as "not found"). */
    private function findOwned(Request $request, int $id): ?Question
    {
        return $this->ownedRecord($request, Question::class, $id, fn (Question $item) => $this->ownerOf($item));
    }

    private function row(Question $q, Request $request): array
    {
        return $q->toArray() + [
            'next_states' => Lifecycle::nextStates($q->getApprovalStatus()),
            // What this viewer may do with it, and why not where they may not.
            'lifecycle' => $this->guidanceFor($request, $q, 'assessment', $this->ownerOf($q), 'question', $this->blockers($q)),
            'answer_error' => $q->answerError(),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'Question', 'entityId' => $q->getId()]),
        ];
    }
}
