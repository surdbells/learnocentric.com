<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Role;
use App\Domain\Entity\RolePermission;
use Doctrine\ORM\EntityManagerInterface;

class PermissionService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Does the given role permit the action on the permission subject? */
    public function can(Role $role, string $permissionCode, string $action): bool
    {
        // super_admin is unconditionally allowed at the platform layer.
        if ($role->getCode() === Role::SUPER_ADMIN) {
            return true;
        }

        /** @var RolePermission|null $grant */
        $grant = $this->em->getRepository(RolePermission::class)->findOneBy([
            'role' => $role,
            'permissionCode' => $permissionCode,
        ]);

        return $grant !== null && $grant->allows($action);
    }

    /** @return array<int,array<string,mixed>> all grants for a role */
    public function grantsFor(Role $role): array
    {
        $grants = $this->em->getRepository(RolePermission::class)->findBy(['role' => $role]);

        return array_map(static fn (RolePermission $g) => $g->toArray(), $grants);
    }
}
