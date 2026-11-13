<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Service\AcademicCalendar;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit P1 #6: institutions onboarded before onboarding created a calendar
 * have no academic session or terms. Give each one the current session with
 * First/Second/Third Term (no dates; the school sets those).
 */
final class Version20261113000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill an academic session + three terms for institutions without one';
    }

    public function up(Schema $schema): void
    {
        $name = $this->connection->quote(AcademicCalendar::sessionName());
        $current = AcademicCalendar::currentSequence();
        $missing = $this->connection->fetchFirstColumn(
            'SELECT i.id FROM institutions i WHERE NOT EXISTS (SELECT 1 FROM academic_sessions s WHERE s.institution_id = i.id)'
        );
        foreach ($missing as $id) {
            $id = (int) $id;
            $this->addSql("INSERT INTO academic_sessions (institution_id, name, status, is_current, created_at, updated_at) VALUES ($id, $name, 'active', true, NOW(), NOW())");
            foreach (AcademicCalendar::TERM_NAMES as $i => $termName) {
                $seq = $i + 1;
                $isCurrent = $seq === $current ? 'true' : 'false';
                $this->addSql(sprintf(
                    "INSERT INTO terms (session_id, name, sequence, status, is_current, created_at, updated_at)
                     SELECT id, %s, %d, 'active', %s, NOW(), NOW() FROM academic_sessions WHERE institution_id = %d ORDER BY id DESC LIMIT 1",
                    $this->connection->quote($termName), $seq, $isCurrent, $id
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Calendars may have been edited since; nothing safe to remove.
    }
}
