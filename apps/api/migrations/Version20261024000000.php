<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add questions.common_wrong_answer — the frequent wrong answer that signals the
 * misconception, captured from the Question Bank bulk upload so the system has
 * the data to flag misconceptions (PDF review R2-7).
 */
final class Version20261024000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add questions.common_wrong_answer for misconception capture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions ADD common_wrong_answer VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions DROP common_wrong_answer');
    }
}
