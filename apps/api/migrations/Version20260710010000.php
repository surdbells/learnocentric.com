<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ASSESSMENT-QUALITY: per-question misconception tag, the new difficulty
 * vocabulary (foundational|moderate|challenging|extension) with a back-compat
 * remap of existing rows, and an editorial moderation status on assessments.
 */
final class Version20260710010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Question misconception_tag + new difficulty vocabulary; Assessment moderation_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions ADD misconception_tag VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE questions ALTER difficulty TYPE VARCHAR(20)');
        // Back-compat: remap the old easy/medium/hard vocabulary.
        $this->addSql("UPDATE questions SET difficulty = 'foundational' WHERE difficulty = 'easy'");
        $this->addSql("UPDATE questions SET difficulty = 'moderate' WHERE difficulty = 'medium'");
        $this->addSql("UPDATE questions SET difficulty = 'challenging' WHERE difficulty = 'hard'");
        $this->addSql("ALTER TABLE assessments ADD moderation_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessments DROP moderation_status');
        // Reverse the difficulty remap before narrowing the column again.
        $this->addSql("UPDATE questions SET difficulty = 'easy' WHERE difficulty = 'foundational'");
        $this->addSql("UPDATE questions SET difficulty = 'medium' WHERE difficulty IN ('moderate', 'extension')");
        $this->addSql("UPDATE questions SET difficulty = 'hard' WHERE difficulty = 'challenging'");
        $this->addSql('ALTER TABLE questions ALTER difficulty TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE questions DROP misconception_tag');
    }
}
