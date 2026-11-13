<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit P1 #12: announcements' email channel is now really delivered (by the
 * announcements:dispatch command). Track when, and to how many. Announcements
 * sent before this existed are marked as handled so they aren't emailed late,
 * and overdue scheduled ones become drafts rather than firing all at once.
 */
final class Version20261113000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add announcements.emailed_at / emailed_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcements ADD emailed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE announcements ADD emailed_count INT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE announcements SET emailed_at = sent_at WHERE status = 'sent'");
        // Scheduled announcements were never dispatched before now. Ones already
        // overdue go back to drafts, so they aren't all sent, late, on deploy.
        $this->addSql("UPDATE announcements SET status = 'draft' WHERE status = 'scheduled' AND scheduled_at < NOW()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcements DROP emailed_at');
        $this->addSql('ALTER TABLE announcements DROP emailed_count');
    }
}
