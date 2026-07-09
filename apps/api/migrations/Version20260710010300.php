<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structured teacher feedback for parent reports (spec §7.5, §18):
 * add optional strengths / practice_needed / parent_support_suggestion to
 * feedback_notes. Backward-compatible — all columns are nullable.
 */
final class Version20260710010300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured parent-reporting fields to feedback_notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_notes ADD strengths TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD practice_needed TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD parent_support_suggestion TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_notes DROP strengths');
        $this->addSql('ALTER TABLE feedback_notes DROP practice_needed');
        $this->addSql('ALTER TABLE feedback_notes DROP parent_support_suggestion');
    }
}
