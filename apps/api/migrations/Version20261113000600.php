<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit P1 #10: the profile form has an Address field that was silently
 * dropped on save. Store it (private to the account owner).
 */
final class Version20261113000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD address TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP address');
    }
}
