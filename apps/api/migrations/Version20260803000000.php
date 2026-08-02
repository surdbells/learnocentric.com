<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `reports` table backing the super-admin reports engine: persisted
 * snapshots of templated platform aggregations (overview, institution
 * performance, subscriptions, growth) with an exportable history.
 */
final class Version20260803000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reports table for the platform reports engine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reports (
                id SERIAL NOT NULL,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(200) NOT NULL,
                params JSON DEFAULT NULL,
                summary JSON DEFAULT NULL,
                data JSON DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ready',
                generated_by_id INT DEFAULT NULL,
                generated_by_name VARCHAR(150) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_reports_type ON reports (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reports');
    }
}
