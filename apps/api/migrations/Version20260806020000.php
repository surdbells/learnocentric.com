<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Communication hub: extend announcements with category, priority, delivery
 * lifecycle (draft/scheduled/sent), optional class/subject targeting, channels,
 * an attachment, scheduling, and a recipient count.
 */
final class Version20260806020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Communication-hub fields to announcements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE announcements ADD category VARCHAR(20) DEFAULT 'general' NOT NULL");
        $this->addSql("ALTER TABLE announcements ADD priority VARCHAR(10) DEFAULT 'medium' NOT NULL");
        $this->addSql("ALTER TABLE announcements ADD status VARCHAR(12) DEFAULT 'sent' NOT NULL");
        $this->addSql('ALTER TABLE announcements ADD class_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD subject_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD channels JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD attachment_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD recipient_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE announcements ADD CONSTRAINT fk_ann_class FOREIGN KEY (class_id) REFERENCES school_classes (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ann_class ON announcements (class_id)');
        // Existing rows are already-sent broadcasts.
        $this->addSql("UPDATE announcements SET status = 'sent', sent_at = created_at");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcements DROP CONSTRAINT fk_ann_class');
        foreach (['category', 'priority', 'status', 'class_id', 'subject_name', 'channels', 'attachment_url', 'scheduled_at', 'sent_at', 'recipient_count'] as $col) {
            $this->addSql("ALTER TABLE announcements DROP {$col}");
        }
    }
}
