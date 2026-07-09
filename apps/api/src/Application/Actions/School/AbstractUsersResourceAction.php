<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared resource action for listing/deleting users of a specific role
 * (students, teachers). GET = paginated list; DELETE ?id = remove one;
 * bulkDelete = remove many. All institution-scoped and authorization-guarded.
 */
abstract class AbstractUsersResourceAction
{
    use ResolvesInstitution;

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly AuditLogger $audit,
    ) {
    }

    abstract protected function roleCode(): string;

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'DELETE' => $this->deleteOne($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['first_name', 'last_name', 'email', 'created_at'], ['is_active'], 'first_name');

        $qb = $this->baseQuery($institution);
        if ($query->q !== '') {
            $qb->andWhere('(LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q OR LOWER(u.email) LIKE :q)')
                ->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['is_active'])) {
            $active = in_array($query->filters['is_active'], ['1', 'true', 'active'], true);
            $qb->andWhere('u.status = :st')->setParameter('st', $active ? 'active' : 'suspended');
        }

        $includeSensitive = $this->callerSeesSensitive($request);
        $mapper = fn (User $u) => $this->userRow($u, $includeSensitive);

        if (!$query->paginated) {
            $qb->orderBy('u.firstName', 'ASC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        $sortMap = ['first_name' => 'u.firstName', 'last_name' => 'u.lastName', 'email' => 'u.email', 'created_at' => 'u.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'u', $query, $sortMap, $mapper));
    }

    private function deleteOne(Request $request, Response $response): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $target = $this->em->getRepository(User::class)->find($id);
        if ($target === null) {
            return Json::error($response, 'User not found.', 404);
        }
        if ($target->getId() === $actor->getId()) {
            return Json::error($response, 'You cannot delete your own account.', 422);
        }
        if (!$this->canManage($actor, $target)) {
            return Json::error($response, 'You are not allowed to delete this user.', 403);
        }

        $before = $target->toArray();
        $this->em->remove($target);
        $this->em->flush();
        $this->audit->log('user.delete', $actor, 'User', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }

        $institution = $this->resolveInstitution($request, $this->em);
        $qb = $this->baseQuery($institution)->andWhere('u.id IN (:ids)')->setParameter('ids', $ids);

        $count = 0;
        foreach ($qb->getQuery()->getResult() as $user) {
            if ($user->getId() !== $actor->getId() && $this->canManage($actor, $user)) {
                $this->em->remove($user);
                $count++;
            }
        }
        $this->em->flush();
        $this->audit->log('user.bulk_delete', $actor, 'User', implode(',', $ids), null, ['count' => $count]);

        return Json::write($response, ['deleted' => $count]);
    }

    private function baseQuery(?object $institution): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join('u.role', 'r')
            ->where('r.code = :role')
            ->setParameter('role', $this->roleCode());
        if ($institution !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $institution);
        }

        return $qb;
    }

    protected function canManage(User $actor, User $target): bool
    {
        $role = $actor->getRole()->getCode();
        if ($role === Role::SUPER_ADMIN) {
            return true;
        }
        if (in_array($role, [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD], true)) {
            $sameInstitution = $actor->getInstitution() !== null
                && $target->getInstitution()?->getId() === $actor->getInstitution()->getId();

            return $sameInstitution && $target->getRole()->getCode() !== Role::SUPER_ADMIN;
        }

        return false;
    }
}
