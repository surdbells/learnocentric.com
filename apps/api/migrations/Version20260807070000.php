<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Interventions depth: priority, type and progress on interventions. */
final class Version20260807070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add priority, type and progress to interventions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE interventions ADD priority VARCHAR(10) DEFAULT 'medium' NOT NULL");
        $this->addSql('ALTER TABLE interventions ADD type VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE interventions ADD progress SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE interventions DROP priority');
        $this->addSql('ALTER TABLE interventions DROP type');
        $this->addSql('ALTER TABLE interventions DROP progress');
    }
}
