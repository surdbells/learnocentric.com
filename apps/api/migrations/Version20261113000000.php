<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custom roles get a base system role (audit P0 #5). Authorization uses
 * roles.effective_code — the role's own code for system roles, the base role's
 * code for custom roles — so staff on a custom role keep their base access and
 * the custom role's permission matrix narrows it.
 *
 * Existing custom roles are based on `teacher` (least-privileged staff role);
 * admins can change the base role from Roles & Permissions.
 */
final class Version20261113000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add roles.base_role_id + roles.effective_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles ADD base_role_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles ADD effective_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('UPDATE roles SET effective_code = code');
        $this->addSql("UPDATE roles SET base_role_id = (SELECT id FROM roles t WHERE t.code = 'teacher'), effective_code = 'teacher' WHERE is_system = false");
        $this->addSql('ALTER TABLE roles ALTER COLUMN effective_code SET NOT NULL');
        $this->addSql('ALTER TABLE roles ADD CONSTRAINT fk_roles_base_role FOREIGN KEY (base_role_id) REFERENCES roles (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_roles_base_role ON roles (base_role_id)');
        $this->addSql('CREATE INDEX idx_roles_effective_code ON roles (effective_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles DROP CONSTRAINT fk_roles_base_role');
        $this->addSql('DROP INDEX idx_roles_base_role');
        $this->addSql('DROP INDEX idx_roles_effective_code');
        $this->addSql('ALTER TABLE roles DROP base_role_id');
        $this->addSql('ALTER TABLE roles DROP effective_code');
    }
}
