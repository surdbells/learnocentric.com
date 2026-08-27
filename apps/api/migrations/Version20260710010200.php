<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Safeguarding cases: add severity, access_level and evidence (spec §15).
 * Backward-compatible, existing rows adopt the column defaults.
 */
final class Version20260710010200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add severity, access_level and evidence to safeguarding_cases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE safeguarding_cases ADD severity VARCHAR(12) DEFAULT 'medium' NOT NULL");
        $this->addSql("ALTER TABLE safeguarding_cases ADD access_level VARCHAR(12) DEFAULT 'standard' NOT NULL");
        $this->addSql('ALTER TABLE safeguarding_cases ADD evidence JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE safeguarding_cases DROP severity');
        $this->addSql('ALTER TABLE safeguarding_cases DROP access_level');
        $this->addSql('ALTER TABLE safeguarding_cases DROP evidence');
    }
}
