<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\GovernedContent;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Term;
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
 * /backend/curriculum/topics, GET (paginated list), POST (create draft),
 * PUT (edit while draft/review), DELETE; plus transition/history sub-actions
 * and bulk delete.
 */
final class TopicsAction
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
        $query = ListQuery::fromRequest($request, ['title', 'week_number', 'approval_status', 'created_at'], ['approval_status', 'subject_id', 'class_id', 'term_id'], 'week_number');

        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(t.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['approval_status'])) {
            $qb->andWhere('t.approvalStatus = :st')->setParameter('st', $query->filters['approval_status']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('t.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }
        if (isset($query->filters['term_id'])) {
            // Term switcher: this term's topics plus any not tied to a term.
            $qb->andWhere('(t.term = :tid OR t.term IS NULL)')->setParameter('tid', (int) $query->filters['term_id']);
        }

        $mapper = fn (Topic $t) => $this->row($t, $request);

        if (!$query->paginated) {
            $qb->orderBy('t.weekNumber', 'ASC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['title' => 't.title', 'week_number' => 't.weekNumber', 'approval_status' => 't.approvalStatus', 'created_at' => 't.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 't', $query, $sortMap, $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? $body['subjectId'] ?? 0));
        if ($title === '' || $subject === null || !$this->canActWithin($request, $subject->getInstitution())) {
            return Json::error($response, 'A title and a valid subject_id are required.', 422);
        }

        $topic = new Topic($subject, $title);
        $topic->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($topic, $body);
        $this->em->persist($topic);
        $this->em->flush();

        $this->audit->log('topic.create', $request->getAttribute('user'), 'Topic', (string) $topic->getId(), null, $topic->toArray());

        return Json::write($response, $this->row($topic, $request), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $topic = $this->findOwned($request, (int) ($body['id'] ?? 0));
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        if (!in_array($topic->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, $this->lockedReasonFor($request, $topic, 'curriculum_pack', $this->ownerOf($topic), 'topic'), 409);
        }
        $before = $topic->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $topic->setTitle((string) $body['title']);
        }
        $this->applyFields($topic, $body);
        $this->em->flush();
        $this->audit->log('topic.update', $request->getAttribute('user'), 'Topic', (string) $topic->getId(), $before, $topic->toArray());

        return Json::write($response, $this->row($topic, $request));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $topic = $this->findOwned($request, $id);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        $before = $topic->toArray();
        $this->em->remove($topic);
        $this->em->flush();
        $this->audit->log('topic.delete', $request->getAttribute('user'), 'Topic', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /backend/curriculum/topics/{id}/transition, body { to, note } */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $topic = $this->findOwned($request, (int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        [$result, $error] = $this->moveItem($request, $topic, 'topic', $topic->getTitle(), $this->ownerOf($topic), '/academics/topics');
        if ($result === null) {
            return Json::error($response, (string) $error, 422);
        }

        return Json::write($response, ['topic' => $this->row($topic, $request)] + $result);
    }

    /** GET /backend/curriculum/topics/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $topic = $this->findOwned($request, (int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($topic)));
    }

    /** POST /backend/curriculum/topics/bulk-delete, body { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Topic::class)->findBy(['id' => $ids]) as $topic) {
            if (!$this->canActWithin($request, $this->ownerOf($topic))) {
                continue; // another school's record
            }
            $this->em->remove($topic);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('topic.bulk_delete', $request->getAttribute('user'), 'Topic', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    /**
     * A list of short strings from an array, or text with one item per line.
     *
     * @return string[]
     */
    private function stringList(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\R/u', (string) $raw);
        $out = [];
        foreach ($items ?: [] as $item) {
            $s = trim((string) (is_scalar($item) ? $item : ''));
            if ($s !== '') {
                $out[] = mb_substr($s, 0, 300);
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 30);
    }

    private function applyFields(Topic $topic, array $body): void
    {
        if (isset($body['week_number']) || isset($body['weekNumber'])) {
            $topic->setWeekNumber((int) ($body['week_number'] ?? $body['weekNumber']) ?: null);
        }
        if (array_key_exists('strand', $body)) { $topic->setStrand($body['strand'] !== '' ? (string) $body['strand'] : null); }
        if (array_key_exists('objective', $body)) { $topic->setObjective($body['objective'] !== '' ? (string) $body['objective'] : null); }
        if (array_key_exists('core_theory', $body)) { $topic->setCoreTheory($body['core_theory'] !== '' ? (string) $body['core_theory'] : null); }
        if (array_key_exists('real_life_relevance', $body)) { $topic->setRealLifeRelevance($body['real_life_relevance'] !== '' ? (string) $body['real_life_relevance'] : null); }
        if (array_key_exists('competency_built', $body)) { $topic->setCompetencyBuilt($body['competency_built'] !== '' ? (string) $body['competency_built'] : null); }
        // Read by insights, analytics and the approval queue, but no form used to send them.
        if (array_key_exists('prerequisites', $body)) { $topic->setPrerequisites(trim((string) $body['prerequisites']) !== '' ? (string) $body['prerequisites'] : null); }
        if (array_key_exists('workplace_relevance', $body)) { $topic->setWorkplaceRelevance(trim((string) $body['workplace_relevance']) !== '' ? (string) $body['workplace_relevance'] : null); }
        if (array_key_exists('misconceptions', $body)) { $topic->setMisconceptions($this->stringList($body['misconceptions'])); }
        if (array_key_exists('question_types', $body)) { $topic->setQuestionTypes($this->stringList($body['question_types'])); }
        if (array_key_exists('class_id', $body)) {
            // Empty = all classes (schoolClass NULL); a value scopes it to that class.
            $topic->setSchoolClass(!empty($body['class_id'])
                ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id'])
                : null);
        }
        if (!empty($body['term_id'])) {
            $topic->setTerm($this->em->getRepository(Term::class)->find((int) $body['term_id']));
        }
    }

    /** The school a topic belongs to. */
    private function ownerOf(Topic $item): ?Institution
    {
        return $item->getSubject()->getInstitution();
    }

    /** A topic the caller's school owns (another school's id reads as "not found"). */
    private function findOwned(Request $request, int $id): ?Topic
    {
        return $this->ownedRecord($request, Topic::class, $id, fn (Topic $item) => $this->ownerOf($item));
    }

    private function row(Topic $t, Request $request): array
    {
        return $t->toArray() + [
            'next_states' => Lifecycle::nextStates($t->getApprovalStatus()),
            // What this viewer may do with it, and why not where they may not.
            'lifecycle' => $this->guidanceFor($request, $t, 'curriculum_pack', $this->ownerOf($t), 'topic'),
            'version_count' => $this->em->getRepository(\App\Domain\Entity\ContentVersion::class)->count(['entityType' => 'Topic', 'entityId' => $t->getId()]),
        ];
    }
}
