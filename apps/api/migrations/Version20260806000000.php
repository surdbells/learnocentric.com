<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-question worksheets: a worksheet can hold structured questions grouped
 * into sections, and a submission holds one response per question (with the
 * mark awarded). Backs the rich Worksheet solver + hybrid grading.
 */
final class Version20260806000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add worksheet_questions and worksheet_responses tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE worksheet_questions (
                id SERIAL NOT NULL,
                worksheet_id INT NOT NULL,
                section_label VARCHAR(160) DEFAULT NULL,
                section_position SMALLINT DEFAULT 0 NOT NULL,
                position SMALLINT DEFAULT 0 NOT NULL,
                prompt TEXT NOT NULL,
                type VARCHAR(20) DEFAULT 'numeric' NOT NULL,
                options JSON DEFAULT NULL,
                correct_answer TEXT DEFAULT NULL,
                marks SMALLINT DEFAULT 1 NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_wq_worksheet ON worksheet_questions (worksheet_id)');
        $this->addSql('ALTER TABLE worksheet_questions ADD CONSTRAINT fk_wq_worksheet FOREIGN KEY (worksheet_id) REFERENCES worksheets (id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE worksheet_responses (
                id SERIAL NOT NULL,
                submission_id INT NOT NULL,
                question_id INT NOT NULL,
                answer TEXT DEFAULT NULL,
                awarded_marks SMALLINT DEFAULT NULL,
                correct BOOLEAN DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_submission_question ON worksheet_responses (submission_id, question_id)');
        $this->addSql('ALTER TABLE worksheet_responses ADD CONSTRAINT fk_wr_submission FOREIGN KEY (submission_id) REFERENCES worksheet_submissions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE worksheet_responses ADD CONSTRAINT fk_wr_question FOREIGN KEY (question_id) REFERENCES worksheet_questions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE worksheet_responses');
        $this->addSql('DROP TABLE worksheet_questions');
    }
}
