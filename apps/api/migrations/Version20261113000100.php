<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Join-code self-registration (audit P0 #2): a school opts in to self sign-up
 * and shares a join code; the public register endpoint no longer accepts a raw
 * institution id.
 */
final class Version20261113000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add institutions.self_signup + institutions.join_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions ADD self_signup BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE institutions ADD join_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_institutions_join_code ON institutions (join_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_institutions_join_code');
        $this->addSql('ALTER TABLE institutions DROP join_code');
        $this->addSql('ALTER TABLE institutions DROP self_signup');
    }
}
