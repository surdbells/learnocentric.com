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
        if ($role->effectiveCode() === Role::SUPER_ADMIN) {
            return true;
        }

        /** @var RolePermission|null $grant */
        $grant = $this->em->getRepository(RolePermission::class)->findOneBy([
            'role' => $role,
            'permissionCode' => $permissionCode,
        ]);

        return $grant !== null && $grant->allows($action);
    }

    /** Human label of a permission module, e.g. "Curriculum packs". */
    public function label(string $permissionCode): string
    {
        $p = $this->em->getRepository(\App\Domain\Entity\Permission::class)->findOneBy(['code' => $permissionCode]);

        return $p?->getDescription() ?? str_replace('_', ' ', $permissionCode);
    }

    /**
     * Module → granted actions for a custom role, used by the client to hide
     * menu entries the role can't open. Null for system roles (not narrowed).
     *
     * @return array<string,string[]>|null
     */
    public function matrixFor(Role $role): ?array
    {
        if ($role->isSystem()) {
            return null;
        }
        $out = [];
        foreach ($this->em->getRepository(RolePermission::class)->findBy(['role' => $role]) as $g) {
            /** @var RolePermission $g */
            $acts = [];
            foreach (['view', 'create', 'edit', 'approve', 'export', 'delete', 'archive'] as $a) {
                if ($g->allows($a)) {
                    $acts[] = $a;
                }
            }
            $out[$g->getPermissionCode()] = $acts;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> all grants for a role */
    public function grantsFor(Role $role): array
    {
        $grants = $this->em->getRepository(RolePermission::class)->findBy(['role' => $role]);

        return array_map(static fn (RolePermission $g) => $g->toArray(), $grants);
    }
}
