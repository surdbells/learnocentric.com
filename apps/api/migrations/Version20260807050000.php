<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** School-scoped custom roles: institution + description on roles. */
final class Version20260807050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add institution scope + description to roles for custom roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles ADD institution_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE roles ADD CONSTRAINT fk_roles_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_roles_institution ON roles (institution_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles DROP CONSTRAINT fk_roles_institution');
        $this->addSql('DROP INDEX idx_roles_institution');
        $this->addSql('ALTER TABLE roles DROP institution_id');
        $this->addSql('ALTER TABLE roles DROP description');
    }
}
