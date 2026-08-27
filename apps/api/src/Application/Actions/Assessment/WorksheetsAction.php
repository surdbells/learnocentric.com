<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\ContentVersion;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Topic;
use App\Domain\Entity\Worksheet;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/assessment/worksheets, topic-linked worksheets (staff), lifecycle-governed. */
final class WorksheetsAction
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
        $query = ListQuery::fromRequest($request, ['title', 'approval_status', 'created_at'], ['topic_id', 'subject_id', 'class_id', 'track', 'approval_status'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('w')->from(Worksheet::class, 'w')->join('w.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(w.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['track'])) {
            $qb->andWhere('w.track = :tr')->setParameter('tr', $query->filters['track']);
        }
        if (isset($query->filters['approval_status'])) {
            $qb->andWhere('w.approvalStatus = :st')->setParameter('st', $query->filters['approval_status']);
        }
        if (isset($query->filters['topic_id'])) {
            $qb->andWhere('w.topic = :tid')->setParameter('tid', (int) $query->filters['topic_id']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('w.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }

        $mapper = fn (Worksheet $w) => $this->row($w);
        if (!$query->paginated) {
            $qb->orderBy('w.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }
        $sortMap = ['title' => 'w.title', 'approval_status' => 'w.approvalStatus', 'created_at' => 'w.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'w', $query, $sortMap, $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['topic_id'] ?? 0));
        if ($title === '' || $topic === null) {
            return Json::error($response, 'A title and a valid topic_id are required.', 422);
        }
        $w = new Worksheet($topic, $title);
        $w->setApprovalStatus(Lifecycle::DRAFT);
        $this->applyFields($w, $body);
        $this->em->persist($w);
        $this->em->flush();
        $this->audit->log('worksheet.create', $request->getAttribute('user'), 'Worksheet', (string) $w->getId(), null, $w->toArray());

        return Json::write($response, $this->row($w), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $w = $this->em->getRepository(Worksheet::class)->find((int) ($body['id'] ?? 0));
        if ($w === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        if (!in_array($w->getApprovalStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This worksheet is {$w->getApprovalStatus()}; return it to draft before editing.", 409);
        }
        $before = $w->toArray();
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $w->setTitle((string) $body['title']);
        }
        $this->applyFields($w, $body);
        $this->em->flush();
        $this->audit->log('worksheet.update', $request->getAttribute('user'), 'Worksheet', (string) $w->getId(), $before, $w->toArray());

        return Json::write($response, $this->row($w));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $w = $this->em->getRepository(Worksheet::class)->find($id);
        if ($w === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $this->em->remove($w);
        $this->em->flush();
        $this->audit->log('worksheet.delete', $request->getAttribute('user'), 'Worksheet', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    public function transition(Request $request, Response $response, array $args): Response
    {
        $w = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($w === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->lifecycle->transition($w, (string) ($body['to'] ?? ''), $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['worksheet' => $this->row($w)] + $result);
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        $w = $this->em->getRepository(Worksheet::class)->find((int) $args['id']);
        if ($w === null) {
            return Json::error($response, 'Worksheet not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($w)));
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Worksheet::class)->findBy(['id' => $ids]) as $w) {
            $this->em->remove($w);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('worksheet.bulk_delete', $request->getAttribute('user'), 'Worksheet', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    private function applyFields(Worksheet $w, array $body): void
    {
        if (isset($body['track'])) { $w->setTrack((string) $body['track']); }
        if (array_key_exists('instructions', $body)) { $w->setInstructions($body['instructions'] !== '' ? (string) $body['instructions'] : null); }
        if (array_key_exists('attachment_url', $body)) { $w->setAttachmentUrl($body['attachment_url'] !== '' ? (string) $body['attachment_url'] : null); }
        if (isset($body['total_marks'])) { $w->setTotalMarks((int) $body['total_marks']); }
        if (array_key_exists('due_date', $body)) {
            $w->setDueDate(!empty($body['due_date']) ? new DateTimeImmutable((string) $body['due_date']) : null);
        }
        if (array_key_exists('class_id', $body)) {
            $w->setSchoolClass(!empty($body['class_id']) ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null);
        }
    }

    private function row(Worksheet $w): array
    {
        return $w->toArray() + [
            'next_states' => Lifecycle::nextStates($w->getApprovalStatus()),
            'version_count' => $this->em->getRepository(ContentVersion::class)->count(['entityType' => 'Worksheet', 'entityId' => $w->getId()]),
        ];
    }
}
