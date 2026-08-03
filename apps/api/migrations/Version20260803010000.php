<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `platform_settings` table: a single JSON row holding platform-wide
 * configuration (general, registration, feature flags, security, integrations)
 * for the super-admin System Settings page.
 */
final class Version20260803010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create platform_settings table for super-admin System Settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE platform_settings (
                id SERIAL NOT NULL,
                data JSON DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_settings');
    }
}
