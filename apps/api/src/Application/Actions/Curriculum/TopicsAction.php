<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
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
 * /backend/curriculum/topics — GET (paginated list), POST (create draft),
 * PUT (edit while draft/review), DELETE; plus transition/history sub-actions
 * and bulk delete.
 */
final class TopicsAction
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
        $query = ListQuery::fromRequest($request, ['title', 'week_number', 'approval_status', 'created_at'], ['approval_status', 'subject_id', 'class_id'], 'week_number');

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

        $mapper = fn (Topic $t) => $this->row($t);

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
        if ($title === '' || $subject === null) {
            return Json::error($response, 'A title and a valid subject_id are required.', 422);
        }

        $topic = new Topic($subject, $title);
        $topic->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($topic, $body);
        $this->em->persist($topic);
        $this->em->flush();

        $this->audit->log('topic.create', $request->getAttribute('user'), 'Topic', (string) $topic->getId(), null, $topic->toArray());

        return Json::write($response, $this->row($topic), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['id'] ?? 0));
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        if (!in_array($topic->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This topic is {$topic->getApprovalStatus()}; return it to draft before editing.", 409);
        }
        $before = $topic->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $topic->setTitle((string) $body['title']);
        }
        $this->applyFields($topic, $body);
        $this->em->flush();
        $this->audit->log('topic.update', $request->getAttribute('user'), 'Topic', (string) $topic->getId(), $before, $topic->toArray());

        return Json::write($response, $this->row($topic));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $topic = $this->em->getRepository(Topic::class)->find($id);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        $before = $topic->toArray();
        $this->em->remove($topic);
        $this->em->flush();
        $this->audit->log('topic.delete', $request->getAttribute('user'), 'Topic', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /backend/curriculum/topics/{id}/transition — body { to, note } */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $topic = $this->em->getRepository(Topic::class)->find((int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->lifecycle->transition($topic, (string) ($body['to'] ?? ''), $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['topic' => $this->row($topic)] + $result);
    }

    /** GET /backend/curriculum/topics/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $topic = $this->em->getRepository(Topic::class)->find((int) $args['id']);
        if ($topic === null) {
            return Json::error($response, 'Topic not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($topic)));
    }

    /** POST /backend/curriculum/topics/bulk-delete — body { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Topic::class)->findBy(['id' => $ids]) as $topic) {
            $this->em->remove($topic);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('topic.bulk_delete', $request->getAttribute('user'), 'Topic', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
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
        if (!empty($body['class_id'])) {
            $topic->setSchoolClass($this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']));
        }
        if (!empty($body['term_id'])) {
            $topic->setTerm($this->em->getRepository(Term::class)->find((int) $body['term_id']));
        }
    }

    private function row(Topic $t): array
    {
        return $t->toArray() + [
            'next_states' => Lifecycle::nextStates($t->getApprovalStatus()),
            'version_count' => $this->em->getRepository(\App\Domain\Entity\ContentVersion::class)->count(['entityType' => 'Topic', 'entityId' => $t->getId()]),
        ];
    }
}
