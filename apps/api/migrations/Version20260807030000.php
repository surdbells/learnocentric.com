<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * School-owned resources: teachers/admins can upload resources scoped to their
 * institution (visible to that school's learners), distinct from platform
 * package resources. Adds a nullable institution_id to content_resources.
 */
final class Version20260807030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add institution scope to content_resources for school-uploaded resources';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_resources ADD institution_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_resources ADD CONSTRAINT fk_cr_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_cr_institution ON content_resources (institution_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_resources DROP CONSTRAINT fk_cr_institution');
        $this->addSql('DROP INDEX idx_cr_institution');
        $this->addSql('ALTER TABLE content_resources DROP institution_id');
    }
}
