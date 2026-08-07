<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ask Tutor: learner questions to tutors + tutor ratings. */
final class Version20260807040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tutor_questions and tutor_ratings for Ask Tutor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tutor_questions (
                id SERIAL PRIMARY KEY,
                student_id INT NOT NULL,
                tutor_id INT DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                question TEXT NOT NULL,
                answer TEXT DEFAULT NULL,
                status VARCHAR(12) DEFAULT 'open' NOT NULL,
                answered_by INT DEFAULT NULL,
                answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_tq_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_tq_tutor FOREIGN KEY (tutor_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_tq_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE SET NULL,
                CONSTRAINT fk_tq_answered_by FOREIGN KEY (answered_by) REFERENCES users (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_tq_student ON tutor_questions (student_id)');
        $this->addSql('CREATE INDEX idx_tq_tutor ON tutor_questions (tutor_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE tutor_ratings (
                id SERIAL PRIMARY KEY,
                student_id INT NOT NULL,
                tutor_id INT NOT NULL,
                rating SMALLINT NOT NULL,
                comment TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_tr_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_tr_tutor FOREIGN KEY (tutor_id) REFERENCES users (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_student_tutor ON tutor_ratings (student_id, tutor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tutor_ratings');
        $this->addSql('DROP TABLE tutor_questions');
    }
}
