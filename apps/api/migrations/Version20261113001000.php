<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content packages have a duration that was never enforced. Record when a
 * package was assigned so its term can expire. Packages already assigned
 * start their term today rather than being expired retroactively.
 */
final class Version20261113001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add institutions.assigned_package_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions ADD assigned_package_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE institutions SET assigned_package_at = NOW() WHERE assigned_package_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions DROP assigned_package_at');
    }
}
