<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Daily study reminders and weekly progress summaries now have a sender
 * (announcements:dispatch). Track when each last went out per learner.
 */
final class Version20261113001200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.study_reminder_on / weekly_summary_on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD study_reminder_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD weekly_summary_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP study_reminder_on');
        $this->addSql('ALTER TABLE users DROP weekly_summary_on');
    }
}
