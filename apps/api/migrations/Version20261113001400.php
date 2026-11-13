<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove columns that no code reads or writes any more.
 *
 *  - institutions.subscription_id   never set; subscriptions point at the school, not the reverse
 *  - live_classes.room_url          left over from the previous video provider; Agora joins by channel name
 *  - teacher_assignments.status     always 'active'; an assignment is removed, not deactivated
 *  - reports.status, reports.params always 'ready' / always empty; reports are generated in one step
 *  - enrollments.session_id         only the seeder set it; the session comes from the enrolment's term
 *
 * Columns that hold recorded data (audit old/new values, tutor rating comments,
 * parent wording) are kept and shown in the app instead.
 */
final class Version20261113001400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions DROP COLUMN IF EXISTS subscription_id');
        $this->addSql('ALTER TABLE live_classes DROP COLUMN IF EXISTS room_url');
        $this->addSql('ALTER TABLE teacher_assignments DROP COLUMN IF EXISTS status');
        $this->addSql('ALTER TABLE reports DROP COLUMN IF EXISTS status');
        $this->addSql('ALTER TABLE reports DROP COLUMN IF EXISTS params');
        // Dropping the column also drops its foreign key and index.
        $this->addSql('ALTER TABLE enrollments DROP COLUMN IF EXISTS session_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions ADD COLUMN IF NOT EXISTS subscription_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE live_classes ADD COLUMN IF NOT EXISTS room_url VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE teacher_assignments ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active' NOT NULL");
        $this->addSql("ALTER TABLE reports ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ready' NOT NULL");
        $this->addSql('ALTER TABLE reports ADD COLUMN IF NOT EXISTS params JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT fk_enrollments_session FOREIGN KEY (session_id) REFERENCES academic_sessions (id) ON DELETE SET NULL');
    }
}
