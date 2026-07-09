<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content lifecycle wiring: give content_packages a lifecycle status column so
 * packages move through Draft → Review → Approved → Published → Archived like
 * every other governed content type (spec §13). Existing active packages are
 * backfilled to 'published', inactive ones to 'draft'. is_active stays as a
 * separate concern. scheme_of_work already carries its own status column.
 */
final class Version20260710020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle status to content_packages and backfill from is_active';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE content_packages ADD status VARCHAR(20) DEFAULT 'draft' NOT NULL");
        $this->addSql("UPDATE content_packages SET status = 'published' WHERE is_active = true");
        $this->addSql("UPDATE content_packages SET status = 'draft' WHERE is_active = false");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_packages DROP status');
    }
}
