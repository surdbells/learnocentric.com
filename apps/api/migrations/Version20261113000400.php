<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TOTP two-factor sign-in (audit P0 #6): per-user encrypted secret, enabled
 * timestamp, hashed backup codes, replay guard and failure lockout counters.
 */
final class Version20261113000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users TOTP two-factor columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD totp_secret TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_backup_codes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_last_step BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_failures SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE users ADD totp_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP totp_secret');
        $this->addSql('ALTER TABLE users DROP totp_enabled_at');
        $this->addSql('ALTER TABLE users DROP totp_backup_codes');
        $this->addSql('ALTER TABLE users DROP totp_last_step');
        $this->addSql('ALTER TABLE users DROP totp_failures');
        $this->addSql('ALTER TABLE users DROP totp_failed_at');
    }
}
