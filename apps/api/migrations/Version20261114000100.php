<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Subscription and pricing model (working proposal, 19 October 2026).
 *
 * Plans gain an audience (school or academy), an optional annual price, an
 * administrator limit, an own-file storage allowance, a "custom quote" marker
 * and a fixed period length for cohort plans. Subscriptions record the term
 * actually paid for, a stopped renewal, a change requested for the next
 * renewal, a term paid ahead, and which reminders have gone out. Payments
 * record what they were for.
 *
 * The proposal's plans are added as DRAFTS (not on sale): their prices are not
 * approved yet. Existing plans, and every school already paying, are untouched.
 */
final class Version20261114000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscription model: plan limits and annual price, renewal/upgrade/cancel state, draft proposal plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE subscription_plans ADD COLUMN audience VARCHAR(20) DEFAULT 'school' NOT NULL");
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN annual_price_kobo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN max_admins INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN storage_gb INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN is_custom BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN period_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD COLUMN sort_order INT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE subscriptions ADD COLUMN billing_interval VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN cancel_at_period_end BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN pending_plan_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN pending_interval VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN next_plan_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN next_interval VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN next_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD COLUMN notices JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_pending_plan FOREIGN KEY (pending_plan_id) REFERENCES subscription_plans (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_next_plan FOREIGN KEY (next_plan_id) REFERENCES subscription_plans (id) ON DELETE SET NULL');

        $this->addSql("ALTER TABLE billing_transactions ADD COLUMN kind VARCHAR(20) DEFAULT 'subscription' NOT NULL");
        $this->addSql('ALTER TABLE billing_transactions ADD COLUMN billing_interval VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_transactions ADD COLUMN tax_kobo INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_transactions ADD COLUMN details JSON DEFAULT NULL');

        // The proposal's plans, as drafts. Prices are in kobo. Modules default to
        // everything currently built; the final feature list is to be confirmed before sale.
        $modules = '["assessments","worksheets","portfolio","live_classes","analytics","interventions"]';
        $plans = [
            // code, name, description, audience, price, interval, annual, learners, teachers, admins, storage GB, custom, period days, order, features
            ['school_starter', 'Starter', 'For smaller schools.', 'school', 15000000, 'termly', 40500000, 200, 15, 2, 30, 'false', 'NULL', 10, '[]'],
            ['school_growth', 'Growth', 'For growing schools.', 'school', 30000000, 'termly', 81000000, 500, 25, 5, 75, 'false', 'NULL', 20, '[]'],
            ['school_professional', 'Professional', 'For larger schools.', 'school', 52500000, 'termly', 141750000, 750, 40, 8, 150, 'false', 'NULL', 30, '[]'],
            ['school_enterprise', 'Enterprise', 'For 1,000 learners or more. Users and storage are agreed in contract.', 'school', 0, 'termly', 'NULL', 'NULL', 'NULL', 'NULL', 'NULL', 'true', 'NULL', 40, '[]'],
            ['academy_pilot', 'Academy pilot (cohort)', 'Six teaching weeks, paid before the start. Platform with academy-owned or licensed content.', 'academy', 4500000, 'cohort', 'NULL', 25, 5, 1, 'NULL', 'false', 42, 10,
                '["Initial setup and tutor training","One roster import","60-minute tutor session and 30-minute admin session","Up to one hour of routine support each week"]'],
            ['academy_continuation', 'Academy continuation', 'Continues the agreed platform service after the pilot. Content subscriptions are separate.', 'academy', 3000000, 'monthly', 'NULL', 25, 5, 1, 'NULL', 'false', 'NULL', 20, '[]'],
        ];
        foreach ($plans as [$code, $name, $description, $audience, $price, $interval, $annual, $learners, $teachers, $admins, $storage, $custom, $days, $order, $features]) {
            $this->addSql(sprintf(
                "INSERT INTO subscription_plans (code, name, description, audience, price_kobo, interval, annual_price_kobo, max_students, max_teachers, max_admins, storage_gb, is_custom, period_days, sort_order, features, modules, is_active, created_at, updated_at)
                 VALUES ('%s', '%s', '%s', '%s', %d, '%s', %s, %s, %s, %s, %s, %s, %s, %d, '%s', '%s', false, NOW(), NOW())
                 ON CONFLICT (code) DO NOTHING",
                $code, $name, $description, $audience, $price, $interval, $annual, $learners, $teachers, $admins, $storage, $custom, $days, $order, $features, $modules,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM subscription_plans WHERE code IN ('school_starter','school_growth','school_professional','school_enterprise','academy_pilot','academy_continuation') AND id NOT IN (SELECT plan_id FROM subscriptions) AND id NOT IN (SELECT plan_id FROM billing_transactions)");
        $this->addSql('ALTER TABLE billing_transactions DROP COLUMN details');
        $this->addSql('ALTER TABLE billing_transactions DROP COLUMN tax_kobo');
        $this->addSql('ALTER TABLE billing_transactions DROP COLUMN billing_interval');
        $this->addSql('ALTER TABLE billing_transactions DROP COLUMN kind');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT fk_subscriptions_next_plan');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT fk_subscriptions_pending_plan');
        foreach (['notices', 'next_period_end', 'next_interval', 'next_plan_id', 'pending_interval', 'pending_plan_id', 'cancelled_at', 'cancel_at_period_end', 'billing_interval'] as $col) {
            $this->addSql('ALTER TABLE subscriptions DROP COLUMN ' . $col);
        }
        foreach (['sort_order', 'period_days', 'is_custom', 'storage_gb', 'max_admins', 'annual_price_kobo', 'audience'] as $col) {
            $this->addSql('ALTER TABLE subscription_plans DROP COLUMN ' . $col);
        }
    }
}
