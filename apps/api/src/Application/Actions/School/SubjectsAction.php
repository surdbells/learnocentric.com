<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Institution;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** /backend/school/subjects — GET list, POST create, PUT update, DELETE remove. */
final class SubjectsAction
{
    use ResolvesInstitution;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
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
        $query = ListQuery::fromRequest($request, ['name', 'code', 'status', 'created_at'], ['class_id', 'status'], 'name');

        $qb = $this->em->createQueryBuilder()->select('s')->from(Subject::class, 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('(LOWER(s.name) LIKE :q OR LOWER(s.code) LIKE :q)')
                ->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('s.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('s.status = :st')->setParameter('st', $query->filters['status']);
        }

        $mapper = static fn (Subject $s) => $s->toArray();

        if (!$query->paginated) {
            $qb->orderBy('s.name', 'ASC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['name' => 's.name', 'code' => 's.code', 'status' => 's.status', 'created_at' => 's.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 's', $query, $sortMap, $mapper));
    }

    /** POST /backend/school/subjects/bulk-delete — body: { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('s')->from(Subject::class, 's')
            ->where('s.id IN (:ids)')->setParameter('ids', $ids);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        $count = 0;
        foreach ($qb->getQuery()->getResult() as $subject) {
            $this->em->remove($subject);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('subject.bulk_delete', $request->getAttribute('user'), 'Subject', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, "Field 'name' is required.", 422);
        }

        $institution = $this->institutionForWrite($request, $body);
        if ($institution === null) {
            return Json::error($response, 'No institution scope; provide institutionId.', 422);
        }

        $subject = new Subject($institution, $name);
        $this->applyFields($subject, $body);
        $this->em->persist($subject);
        $this->em->flush();

        $this->audit->log('subject.create', $request->getAttribute('user'), 'Subject', (string) $subject->getId(), null, $subject->toArray());

        return Json::write($response, $subject->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['id'] ?? 0));
        if ($subject === null) {
            return Json::error($response, 'Subject not found.', 404);
        }
        $before = $subject->toArray();
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $subject->setName((string) $body['name']);
        }
        $this->applyFields($subject, $body);
        $this->em->flush();

        $this->audit->log('subject.update', $request->getAttribute('user'), 'Subject', (string) $subject->getId(), $before, $subject->toArray());

        return Json::write($response, $subject->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $subject = $this->em->getRepository(Subject::class)->find($id);
        if ($subject === null) {
            return Json::error($response, 'Subject not found.', 404);
        }
        $before = $subject->toArray();
        $this->em->remove($subject);
        $this->em->flush();

        $this->audit->log('subject.delete', $request->getAttribute('user'), 'Subject', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    private function applyFields(Subject $subject, array $body): void
    {
        if (array_key_exists('code', $body)) {
            $subject->setCode($body['code'] !== null && $body['code'] !== '' ? (string) $body['code'] : null);
        }
        if (!empty($body['class_id']) || !empty($body['classId'])) {
            $classId = (int) ($body['class_id'] ?? $body['classId']);
            $subject->setSchoolClass($this->em->getRepository(SchoolClass::class)->find($classId));
        }
        if (!empty($body['curriculum_source'])) {
            $subject->setCurriculumSource((string) $body['curriculum_source']);
        }
    }

    private function institutionForWrite(Request $request, array $body): ?Institution
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $institution = $user?->getInstitution();
        if ($institution === null && !empty($body['institutionId'])) {
            $institution = $this->em->find(Institution::class, (int) $body['institutionId']);
        }

        return $institution;
    }
}
