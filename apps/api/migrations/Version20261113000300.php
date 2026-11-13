<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Domain\SystemRoleMatrix;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RBAC enforcement (audit P0 #5): system-role grants become an enforced
 * security floor, so replace the old advisory rows with the canonical
 * SystemRoleMatrix (which matches what each role's screens actually do).
 */
final class Version20261113000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync system-role permission grants to the enforced SystemRoleMatrix';
    }

    public function up(Schema $schema): void
    {
        foreach (SystemRoleMatrix::all() as $roleCode => $modules) {
            $role = $this->connection->quote($roleCode);
            $this->addSql("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE code = $role AND is_system = true)");
            foreach ($modules as $module => $f) {
                $this->addSql(sprintf(
                    "INSERT INTO role_permissions (role_id, permission_code, can_view, can_create, can_edit, can_approve, can_export, can_delete, can_archive, scope)
                     SELECT id, %s, %s, %s, %s, %s, %s, %s, %s, scope FROM roles WHERE code = %s AND is_system = true",
                    $this->connection->quote($module),
                    $f['view'] ? 'true' : 'false', $f['create'] ? 'true' : 'false', $f['edit'] ? 'true' : 'false',
                    $f['approve'] ? 'true' : 'false', $f['export'] ? 'true' : 'false', $f['delete'] ? 'true' : 'false',
                    $f['archive'] ? 'true' : 'false',
                    $role
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The previous rows were advisory and inaccurate; nothing to restore.
    }
}
