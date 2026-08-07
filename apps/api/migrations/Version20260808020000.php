<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Onboarding fields: gender, admission number and an onboarding JSON blob on users. */
final class Version20260808020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gender, admission_number and onboarding blob to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD gender VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD admission_number VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD onboarding JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP gender');
        $this->addSql('ALTER TABLE users DROP admission_number');
        $this->addSql('ALTER TABLE users DROP onboarding');
    }
}
