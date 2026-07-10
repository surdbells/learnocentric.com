<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content-library resource governance (spec §17): audience, visibility and
 * downloadable flags on content_resources.
 */
final class Version20260710010100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audience, visibility and downloadable governance fields to content_resources.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE content_resources ADD audience VARCHAR(20) DEFAULT 'learner' NOT NULL");
        $this->addSql("ALTER TABLE content_resources ADD visibility VARCHAR(20) DEFAULT 'published' NOT NULL");
        $this->addSql('ALTER TABLE content_resources ADD downloadable BOOLEAN DEFAULT true NOT NULL');

        // Existing rows: keep them learner-facing and published so current delivery is unchanged.
        $this->addSql("UPDATE content_resources SET audience = 'learner', visibility = 'published', downloadable = true");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_resources DROP audience');
        $this->addSql('ALTER TABLE content_resources DROP visibility');
        $this->addSql('ALTER TABLE content_resources DROP downloadable');
    }
}
