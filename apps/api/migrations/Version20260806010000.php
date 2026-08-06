<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structured feedback breakdown (design: Feedback_LD): extend feedback_notes
 * with score, common error, next step, teacher-rated focus areas, the source
 * it relates to (quiz / worksheet / portfolio), and an optional marked-work file.
 */
final class Version20260806010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured breakdown fields to feedback_notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_notes ADD score SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD common_error TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD next_step TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD focus_areas JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD source_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD source_title VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD subject_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE feedback_notes ADD attachment_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['score', 'common_error', 'next_step', 'focus_areas', 'source_type', 'source_title', 'subject_name', 'attachment_url'] as $col) {
            $this->addSql("ALTER TABLE feedback_notes DROP {$col}");
        }
    }
}
