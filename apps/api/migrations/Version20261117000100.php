<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Self onboarding from the public website.
 *
 * An organisation that signs itself up waits for its first verified payment
 * before anything but billing opens. "signup_source" records how it came to the
 * platform; "awaiting_payment" holds it closed until the first payment.
 *
 * Also stores every academy under one type name: the onboarding form saved
 * "tutoring_academy" while the seed used "academy", so code comparing either
 * one alone treated some academies as schools.
 */
final class Version20261117000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Self onboarding: signup source and awaiting-payment flag; one academy type name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE institutions ADD COLUMN signup_source VARCHAR(20) DEFAULT 'platform' NOT NULL");
        $this->addSql('ALTER TABLE institutions ADD COLUMN awaiting_payment BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE institutions SET type = 'tutoring_academy' WHERE type = 'academy'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institutions DROP COLUMN awaiting_payment');
        $this->addSql('ALTER TABLE institutions DROP COLUMN signup_source');
    }
}
