<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Institution;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** /backend/school/classes — GET list, POST create, PUT update, DELETE remove. */
final class ClassesAction
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
        $query = ListQuery::fromRequest($request, ['name', 'grade_level', 'level', 'arm', 'status', 'created_at'], ['status'], 'name');

        $qb = $this->em->createQueryBuilder()->select('c')->from(SchoolClass::class, 'c');
        if ($institution !== null) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('(LOWER(c.level) LIKE :q OR LOWER(c.arm) LIKE :q)')
                ->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('c.status = :st')->setParameter('st', $query->filters['status']);
        }

        if (!$query->paginated) {
            $qb->orderBy('c.level', 'ASC')->addOrderBy('c.arm', 'ASC');
            return Json::write($response, array_map([$this, 'row'], $qb->getQuery()->getResult()));
        }

        $sortMap = ['name' => 'c.level', 'grade_level' => 'c.level', 'level' => 'c.level', 'arm' => 'c.arm', 'status' => 'c.status', 'created_at' => 'c.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'c', $query, $sortMap, [$this, 'row']));
    }

    /** POST /backend/school/classes/bulk-delete — body: { ids: number[] } */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('c')->from(SchoolClass::class, 'c')
            ->where('c.id IN (:ids)')->setParameter('ids', $ids);
        if ($institution !== null) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', $institution);
        }

        $count = 0;
        foreach ($qb->getQuery()->getResult() as $class) {
            $this->em->remove($class);
            $count++;
        }
        $this->em->flush();
        $this->audit->log('class.bulk_delete', $request->getAttribute('user'), 'SchoolClass', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        // Frontend sends: name, gradeLevel, section, academicYear.
        $level = trim((string) ($body['gradeLevel'] ?? $body['level'] ?? $body['name'] ?? ''));
        if ($level === '') {
            return Json::error($response, "Field 'gradeLevel' (or 'name') is required.", 422);
        }

        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $institution = $user?->getInstitution();
        if ($institution === null && !empty($body['institutionId'])) {
            $institution = $this->em->find(Institution::class, (int) $body['institutionId']);
        }
        if ($institution === null) {
            return Json::error($response, 'No institution scope; provide institutionId.', 422);
        }

        $arm = ($body['section'] ?? $body['arm'] ?? null);
        $class = new SchoolClass($institution, $level, $arm !== '' ? $arm : null);
        $this->em->persist($class);
        $this->em->flush();

        $this->audit->log('class.create', $user, 'SchoolClass', (string) $class->getId(), null, $class->toArray());

        return Json::write($response, $this->row($class), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $class = $this->em->getRepository(SchoolClass::class)->find((int) ($body['id'] ?? 0));
        if ($class === null) {
            return Json::error($response, 'Class not found.', 404);
        }
        $before = $class->toArray();
        if (!empty($body['gradeLevel'])) { $class->setLevel((string) $body['gradeLevel']); }
        if (array_key_exists('section', $body)) { $class->setArm($body['section'] !== '' ? (string) $body['section'] : null); }
        if (!empty($body['status'])) { $class->setStatus((string) $body['status']); }
        $this->em->flush();

        $this->audit->log('class.update', $request->getAttribute('user'), 'SchoolClass', (string) $class->getId(), $before, $class->toArray());

        return Json::write($response, $this->row($class));
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $class = $this->em->getRepository(SchoolClass::class)->find($id);
        if ($class === null) {
            return Json::error($response, 'Class not found.', 404);
        }
        $before = $class->toArray();
        $this->em->remove($class);
        $this->em->flush();

        $this->audit->log('class.delete', $request->getAttribute('user'), 'SchoolClass', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    public function row(SchoolClass $c): array
    {
        return $c->toArray() + ['name' => $c->getLabel(), 'grade_level' => $c->getLevel()];
    }
}
