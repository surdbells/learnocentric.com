<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Table-driven permission grant: for a given role + permission subject, which
 * actions are allowed and at what scope (per the spec's Role Permission object).
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_role_permission', columns: ['role_id', 'permission_code'])]
class RolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(name: 'permission_code', length: 100)]
    private string $permissionCode;

    #[ORM\Column(name: 'can_view', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canView = false;

    #[ORM\Column(name: 'can_create', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canCreate = false;

    #[ORM\Column(name: 'can_edit', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canEdit = false;

    #[ORM\Column(name: 'can_approve', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canApprove = false;

    #[ORM\Column(name: 'can_export', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canExport = false;

    #[ORM\Column(name: 'can_delete', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $canDelete = false;

    /** platform | school | own */
    #[ORM\Column(length: 20, options: ['default' => 'school'])]
    private string $scope = 'school';

    public function __construct(Role $role, string $permissionCode, array $actions = [], string $scope = 'school')
    {
        $this->role = $role;
        $this->permissionCode = $permissionCode;
        $this->canView = (bool) ($actions['view'] ?? false);
        $this->canCreate = (bool) ($actions['create'] ?? false);
        $this->canEdit = (bool) ($actions['edit'] ?? false);
        $this->canApprove = (bool) ($actions['approve'] ?? false);
        $this->canExport = (bool) ($actions['export'] ?? false);
        $this->canDelete = (bool) ($actions['delete'] ?? false);
        $this->scope = $scope;
    }

    public function getRole(): Role { return $this->role; }
    public function getPermissionCode(): string { return $this->permissionCode; }
    public function getScope(): string { return $this->scope; }

    public function allows(string $action): bool
    {
        return match ($action) {
            'view' => $this->canView,
            'create' => $this->canCreate,
            'edit' => $this->canEdit,
            'approve' => $this->canApprove,
            'export' => $this->canExport,
            'delete' => $this->canDelete,
            default => false,
        };
    }

    public function toArray(): array
    {
        return [
            'permission_code' => $this->permissionCode,
            'can_view' => $this->canView,
            'can_create' => $this->canCreate,
            'can_edit' => $this->canEdit,
            'can_approve' => $this->canApprove,
            'can_export' => $this->canExport,
            'can_delete' => $this->canDelete,
            'scope' => $this->scope,
        ];
    }
}
