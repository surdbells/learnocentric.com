<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Live Class Reminders" in user settings now has a sender: a starts-soon
 * notification. Track that it went out so it is sent once per class.
 */
final class Version20261113000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add live_classes.reminded_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_classes ADD reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_classes DROP reminded_at');
    }
}
