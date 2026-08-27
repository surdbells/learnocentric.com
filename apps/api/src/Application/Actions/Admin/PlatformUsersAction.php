<?php

declare(strict_types=1);

namespace App\Application\Actions\Admin;

use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/admin/users, platform-wide user directory (super admin only).
 *
 * Lists every user across institutions with role + institution resolved, plus
 * per-role stats for the Users & Roles page. Read-only.
 */
final class PlatformUsersAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform super admin can view the user directory.', 403);
        }

        $q = $request->getQueryParams();
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 25)));
        $page = max(1, (int) ($q['page'] ?? 1));
        $role = trim((string) ($q['role'] ?? ''));
        $search = trim((string) ($q['q'] ?? ''));

        $base = $this->em->createQueryBuilder()
            ->from(User::class, 'u')
            ->join('u.role', 'r')
            ->leftJoin('u.institution', 'i');

        if ($role !== '') {
            $base->andWhere('r.code = :role')->setParameter('role', $role);
        }
        if ($search !== '') {
            $base->andWhere('(LOWER(u.firstName) LIKE :s OR LOWER(u.lastName) LIKE :s OR LOWER(u.email) LIKE :s)')
                ->setParameter('s', '%' . strtolower($search) . '%');
        }

        $total = (int) (clone $base)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $rows = $base
            ->select('u.id AS id, u.email AS email, u.firstName AS first_name, u.lastName AS last_name, u.status AS status, u.profileImageUrl AS profile_image_url, r.code AS role, r.name AS role_name, i.name AS institution')
            ->orderBy('u.firstName', 'ASC')->addOrderBy('u.lastName', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getArrayResult();

        return Json::write($response, [
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
            'stats' => $this->stats(),
        ]);
    }

    private function stats(): array
    {
        $byRole = $this->em->createQueryBuilder()
            ->select('r.code AS role, r.name AS name, COUNT(u.id) AS c')
            ->from(User::class, 'u')->join('u.role', 'r')
            ->groupBy('r.code, r.name')
            ->orderBy('c', 'DESC')
            ->getQuery()->getArrayResult();

        $total = 0;
        $roles = array_map(static function (array $r) use (&$total): array {
            $count = (int) $r['c'];
            $total += $count;
            return ['role' => $r['role'], 'name' => $r['name'], 'count' => $count];
        }, $byRole);

        $suspended = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')->from(User::class, 'u')
            ->where("u.status != 'active'")
            ->getQuery()->getSingleScalarResult();

        return ['total' => $total, 'suspended' => $suspended, 'by_role' => $roles];
    }
}
