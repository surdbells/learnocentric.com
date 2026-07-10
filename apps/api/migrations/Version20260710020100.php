<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `can_archive` action to role permissions (spec §6 permission matrix:
 * view/create/edit/approve/export/delete/archive).
 */
final class Version20260710020100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add can_archive to role_permissions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE role_permissions ADD can_archive BOOLEAN DEFAULT false NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_permissions DROP can_archive');
    }
}
