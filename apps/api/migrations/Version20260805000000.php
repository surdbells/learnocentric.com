<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a per-user `preferences` JSON column to `users`, backing the role-aware
 * Settings hubs (notifications, appearance, privacy, learning/grading/comms prefs).
 * A single merged-over-defaults blob per user; secrets are never stored here.
 */
final class Version20260805000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add preferences JSON column to users for the Settings hubs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD preferences JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP preferences');
    }
}
