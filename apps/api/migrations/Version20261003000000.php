<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Template-based submission (Worksheet + Portfolio Task), phase B0 data model.
 *
 * - worksheets.response_mode: 'solver' (structured on-screen questions) vs
 *   'template_upload' (download generated template, complete offline, re-upload).
 * - worksheets.worked_example: worked-example block for the generated template.
 * - topics.portfolio_task: structured portfolio-task content (aim, sub-aim,
 *   mission, evidence list, rubric) used to generate the branded task sheet.
 */
final class Version20261003000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add worksheet response_mode + worked_example and topic portfolio_task template fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE worksheets ADD response_mode VARCHAR(20) NOT NULL DEFAULT 'solver'");
        $this->addSql('ALTER TABLE worksheets ADD worked_example TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE topics ADD portfolio_task JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worksheets DROP response_mode');
        $this->addSql('ALTER TABLE worksheets DROP worked_example');
        $this->addSql('ALTER TABLE topics DROP portfolio_task');
    }
}
