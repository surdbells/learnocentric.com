<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Domain\Entity\BillingTransaction;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Service\PlatformSettings;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The subscription rules (working proposal, 19 October 2026) in one place.
 *
 *  - User limits: named users enabled for access are counted against the plan.
 *    Disabling one login frees its place for a replacement.
 *  - Order: what a chosen plan costs right now and what it changes, shown in
 *    full before payment (quote()), then applied once payment is verified (apply()).
 *  - Upgrade: at once, for the old-to-new fee difference on the same kind of
 *    term, times unused days over total days in that term.
 *  - Downgrade: at the next renewal, and only when users fit the lower plan.
 *    Nothing is ever deleted to make an organisation fit.
 *  - Renewal: from 30 days before the term ends; the new term starts when the
 *    current one ends. Paying during the grace period also starts the new term
 *    at the last paid day, so the grace days are not a free trial.
 *  - Cancellation: stops renewal; access runs to the last paid day.
 */
final class SubscriptionService
{
    /** How long before the end of a term its renewal can be paid. */
    public const RENEWAL_WINDOW_DAYS = 30;

    /** Roles counted against each plan limit. Parents are not counted. */
    private const CATEGORY = [
        Role::STUDENT => 'learners',
        Role::TEACHER => 'teachers',
        Role::SCHOOL_ADMIN => 'admins',
        Role::TUTOR_ADMIN => 'admins',
        Role::ACADEMIC_LEAD => 'admins',
    ];

    private const NOUN = [
        'learners' => ['learner', 'learners'],
        'teachers' => ['teacher or tutor', 'teachers or tutors'],
        'admins' => ['administrator', 'administrators'],
    ];

    private const INTERVAL_LABEL = [
        'termly' => 'term', 'yearly' => 'year', 'monthly' => 'month', 'cohort' => 'cohort',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlatformSettings $settings,
    ) {
    }

    /** The organisation's subscription, moved on to a term that was paid ahead when that term has begun. */
    public function current(Institution $institution, ?DateTimeImmutable $now = null): ?Subscription
    {
        $sub = $this->em->getRepository(Subscription::class)->findOneBy(['institution' => $institution], ['id' => 'DESC']);
        if ($sub !== null && $sub->rollOver($now)) {
            $this->em->flush();
        }

        return $sub;
    }

    /** Which plan limit a role counts against, or null when it is not counted. */
    public function categoryOf(string $effectiveRoleCode): ?string
    {
        return self::CATEGORY[$effectiveRoleCode] ?? null;
    }

    /**
     * Named users currently enabled for access.
     *
     * @return array{learners: int, teachers: int, admins: int}
     */
    public function usage(Institution $institution): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('r.effectiveCode AS effective, r.code AS code, COUNT(u.id) AS n')
            ->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')->andWhere('u.status = :active')
            ->setParameter('inst', $institution)->setParameter('active', 'active')
            ->groupBy('r.effectiveCode, r.code')
            ->getQuery()->getArrayResult();

        $out = ['learners' => 0, 'teachers' => 0, 'admins' => 0];
        foreach ($rows as $row) {
            $code = (string) ($row['effective'] !== null && $row['effective'] !== '' ? $row['effective'] : $row['code']);
            $category = self::CATEGORY[$code] ?? null;
            if ($category !== null) {
                $out[$category] += (int) $row['n'];
            }
        }

        return $out;
    }

    /** @return array{learners: ?int, teachers: ?int, admins: ?int} null means no limit on the plan */
    public function limitsOf(SubscriptionPlan $plan): array
    {
        return ['learners' => $plan->getMaxStudents(), 'teachers' => $plan->getMaxTeachers(), 'admins' => $plan->getMaxAdmins()];
    }

    /**
     * Why one more user of this role cannot be enabled, or null when there is room.
     * An organisation with no subscription yet has no plan, so no limit.
     */
    public function limitRefusal(Institution $institution, string $effectiveRoleCode): ?string
    {
        if (!$this->settings->bool('billing.enforce_user_limits')) {
            return null;
        }
        $category = $this->categoryOf($effectiveRoleCode);
        $sub = $category === null ? null : $this->current($institution);
        if ($sub === null) {
            return null;
        }
        $limit = $this->limitsOf($sub->getPlan())[$category];
        $used = $this->usage($institution)[$category];
        if ($limit === null || $used < $limit) {
            return null;
        }
        [$one, $many] = self::NOUN[$category];

        return sprintf(
            'Your %s plan includes %d active %s and all of those places are in use. To add another %s, disable a login that is no longer needed (its place can be reused) or move to a larger plan under Billing.',
            $sub->getPlan()->getName(),
            $limit,
            $limit === 1 ? $one : $many,
            $one,
        );
    }

    /** Why the organisation's current users do not fit a plan, or null when they do. */
    public function fitRefusal(Institution $institution, SubscriptionPlan $plan): ?string
    {
        $usage = $this->usage($institution);
        $over = [];
        foreach ($this->limitsOf($plan) as $category => $limit) {
            if ($limit !== null && $usage[$category] > $limit) {
                [$one, $many] = self::NOUN[$category];
                $over[] = sprintf('%d active %s (the plan includes %d)', $usage[$category], $usage[$category] === 1 ? $one : $many, $limit);
            }
        }
        if ($over === []) {
            return null;
        }

        return sprintf(
            'The %s plan is too small for the account as it stands: you have %s. Disable the logins you no longer need first, or choose a larger plan. Nothing is removed automatically.',
            $plan->getName(),
            implode(' and ', $over),
        );
    }

    /**
     * What choosing this plan means right now: whether it is allowed, what kind
     * of order it is, what is payable (in full, tax included) and for which dates.
     *
     * @return array<string, mixed>
     */
    public function quote(Institution $institution, SubscriptionPlan $plan, ?string $interval = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $sub = $this->current($institution, $now);
        $status = $sub?->status($now);
        $current = $sub?->getPlan();
        $isCurrent = $current !== null && $current->getId() === $plan->getId();
        $audience = $institution->getType() === 'academy' ? 'academy' : 'school';

        $refuse = static fn (string $why): array => ['ok' => false, 'error' => $why];

        if ($plan->getAudience() !== $audience && !$isCurrent) {
            return $refuse(sprintf('The %s plan is for %s.', $plan->getName(), $plan->getAudience() === 'academy' ? 'academies' : 'schools'));
        }
        if ($plan->isCustom()) {
            return $refuse(sprintf('The %s plan is quoted and agreed in a contract. Please contact us to arrange it.', $plan->getName()));
        }
        // A plan that is no longer (or not yet) on sale can still be renewed by an organisation already on it.
        if (!$plan->isActive() && !$isCurrent) {
            return $refuse('That plan is not on sale.');
        }
        if ($plan->getPriceKobo() <= 0) {
            return $refuse('That plan has no price set yet.');
        }

        $active = $sub !== null && $status === Subscription::ACTIVE;
        $termKind = $active ? $sub->getBillingInterval() : null;

        // ---------- An upgrade or downgrade in the middle of a paid term ----------
        if ($active && !$isCurrent) {
            $oldFee = $current->priceFor($termKind) ?? $current->getPriceKobo();
            $newFee = $plan->priceFor($termKind);
            if ($newFee === null) {
                return $refuse(sprintf(
                    'The %s plan is not offered by the %s, which is how your current term is billed. You can move to it when you renew.',
                    $plan->getName(),
                    self::INTERVAL_LABEL[$termKind] ?? $termKind,
                ));
            }
            if ($newFee > $oldFee) {
                return $this->upgradeQuote($sub, $plan, $oldFee, $newFee, $now);
            }
            // Lower or equal fee: it takes effect at the next renewal.
            if (($fit = $this->fitRefusal($institution, $plan)) !== null) {
                return $refuse($fit);
            }
            $nextKind = $this->chosenInterval($plan, $interval, $plan->getInterval());
            if ($nextKind === null) {
                return $refuse('That plan is not offered for that billing period.');
            }
            if ($sub->hasPaidAhead()) {
                return $refuse(sprintf('Your next term is already paid for, to %s. A change of plan can be chosen once that term has begun.', $sub->paidUntil()->format('j F Y')));
            }
            if ($this->renewalOpen($sub, $now)) {
                // Inside the renewal window the next term can be paid for on the new plan straight away.
                return $this->termQuote($plan, $nextKind, 'renewal', $sub->getPeriodEnd(), $now, [
                    sprintf('Your %s plan stays as it is until %s. The %s plan starts then.', $current->getName(), $sub->getPeriodEnd()->format('j F Y'), $plan->getName()),
                ]);
            }

            return [
                'ok' => true,
                'kind' => 'downgrade',
                'pay_now' => false,
                'plan_id' => $plan->getId(),
                'plan' => $plan->getName(),
                'interval' => $nextKind,
                'effective_on' => $sub->getPeriodEnd()->format(DATE_ATOM),
                'lines' => [],
                'subtotal_kobo' => 0, 'tax_kobo' => 0, 'tax_percent' => $this->taxPercent(), 'total_kobo' => 0,
                'notes' => [
                    sprintf('Nothing is payable now. Your %s plan and its limits stay as they are until %s.', $current->getName(), $sub->getPeriodEnd()->format('j F Y')),
                    sprintf('From your next renewal you will be on %s at %s per %s.', $plan->getName(), $this->naira((int) $plan->priceFor($nextKind)), self::INTERVAL_LABEL[$nextKind] ?? $nextKind),
                    'Your users must still fit the smaller plan on the renewal date. Nothing is deleted to make them fit.',
                ],
            ];
        }

        $kind = $this->chosenInterval($plan, $interval, $termKind ?? $plan->getInterval());
        if ($kind === null) {
            return $refuse('That plan is not offered for that billing period.');
        }

        // ---------- Renewing a term that is still running ----------
        if ($active) {
            if ($sub->hasPaidAhead()) {
                return $refuse(sprintf('Your next term is already paid for, to %s.', $sub->paidUntil()->format('j F Y')));
            }
            if (!$this->renewalOpen($sub, $now)) {
                if ($kind !== $termKind) {
                    // A different billing period for the same plan is a change at renewal.
                    return [
                        'ok' => true, 'kind' => 'downgrade', 'pay_now' => false,
                        'plan_id' => $plan->getId(), 'plan' => $plan->getName(), 'interval' => $kind,
                        'effective_on' => $sub->getPeriodEnd()->format(DATE_ATOM),
                        'lines' => [], 'subtotal_kobo' => 0, 'tax_kobo' => 0, 'tax_percent' => $this->taxPercent(), 'total_kobo' => 0,
                        'notes' => [sprintf(
                            'Nothing is payable now. Your current term runs to %s; from your next renewal you will be billed %s per %s.',
                            $sub->getPeriodEnd()->format('j F Y'),
                            $this->naira((int) $plan->priceFor($kind)),
                            self::INTERVAL_LABEL[$kind] ?? $kind,
                        )],
                    ];
                }

                return $refuse(sprintf(
                    'Your %s plan is paid to %s. It can be renewed from %s.',
                    $plan->getName(),
                    $sub->getPeriodEnd()->format('j F Y'),
                    $sub->getPeriodEnd()->modify('-' . self::RENEWAL_WINDOW_DAYS . ' days')->format('j F Y'),
                ));
            }

            return $this->termQuote($plan, $kind, 'renewal', $sub->getPeriodEnd(), $now, [
                sprintf('Your current term runs to %s. This payment covers the term that follows it.', $sub->getPeriodEnd()->format('j F Y')),
            ]);
        }

        // ---------- Starting, or coming back after the paid period ended ----------
        if (($fit = $this->fitRefusal($institution, $plan)) !== null) {
            return $refuse($fit);
        }
        if ($sub !== null && $status === Subscription::GRACE) {
            return $this->termQuote($plan, $kind, 'renewal', $sub->paidUntil(), $now, [
                sprintf('Your paid period ended on %s. The new term is counted from that day, so the days since then are part of it.', $sub->paidUntil()->format('j F Y')),
            ]);
        }

        return $this->termQuote($plan, $kind, $sub === null ? 'new' : 'renewal', $now, $now, $sub === null ? [] : [
            'Access for tutors and learners is restored as soon as the payment is verified.',
        ]);
    }

    /**
     * Carry out a verified payment. The dates are worked out again from the
     * state at the moment of payment, which may be later than the quote.
     */
    public function apply(BillingTransaction $txn, ?DateTimeImmutable $now = null): Subscription
    {
        $now ??= new DateTimeImmutable();
        $institution = $txn->getInstitution();
        $plan = $txn->getPlan();
        $kind = $txn->getBillingInterval() ?? $plan->getInterval();
        $sub = $this->current($institution, $now);

        if ($sub === null) {
            $sub = new Subscription($institution, $plan, $now, $plan->periodEndFrom($now, $kind));
            $sub->setBillingInterval($kind);
            $this->em->persist($sub);

            return $sub;
        }

        $status = $sub->status($now);
        if ($txn->getKind() === 'upgrade' && $status === Subscription::ACTIVE) {
            // The larger plan and its limits apply at once; the term's dates do not change.
            $sub->setPlan($plan);
            if ($sub->hasPaidAhead()) {
                $sub->setNextPlan($plan);
            }
            $sub->setPendingChange(null, null);

            return $sub;
        }

        if ($status === Subscription::ACTIVE) {
            // Paid before the term ended: the new term follows the one already paid for.
            $from = $sub->paidUntil();
            $sub->setPaidAhead($plan, $kind, $plan->periodEndFrom($from, $kind));
        } else {
            // In grace the new term is counted from the last paid day; after suspension it starts today.
            $from = $status === Subscription::GRACE ? $sub->paidUntil() : $now;
            $sub->setPlan($plan);
            $sub->setBillingInterval($kind);
            $sub->setPeriodStart($from);
            $sub->setPeriodEnd($plan->periodEndFrom($from, $kind));
        }
        $sub->setCancelled(false);
        $sub->resumeRenewal();
        $sub->setPendingChange(null, null);

        return $sub;
    }

    /**
     * Everything the billing screen shows about where the account stands.
     *
     * @return array<string, mixed>
     */
    public function overview(Institution $institution, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $sub = $this->current($institution, $now);
        $status = $sub?->status($now) ?? 'none';
        $usage = $this->usage($institution);
        $limits = $sub === null ? ['learners' => null, 'teachers' => null, 'admins' => null] : $this->limitsOf($sub->getPlan());

        return [
            'subscription' => $sub?->toArray(),
            'status' => $status,
            'has_access' => in_array($status, [Subscription::ACTIVE, Subscription::GRACE], true),
            'usage' => $usage,
            'limits' => $limits,
            'storage_gb' => $sub?->getPlan()->getStorageGb(),
            'limits_enforced' => $this->settings->bool('billing.enforce_user_limits'),
            'suspension_enforced' => $this->settings->bool('billing.suspend_after_grace'),
            'renewal_open' => $sub !== null && $status === Subscription::ACTIVE && !$sub->hasPaidAhead() && $this->renewalOpen($sub, $now),
            'renewal_opens_on' => $sub !== null && $status === Subscription::ACTIVE
                ? $sub->getPeriodEnd()->modify('-' . self::RENEWAL_WINDOW_DAYS . ' days')->format(DATE_ATOM) : null,
            'audience' => $institution->getType() === 'academy' ? 'academy' : 'school',
            'tax_percent' => $this->taxPercent(),
        ];
    }

    /** Is tutor and learner access suspended for this organisation right now? */
    public function isSuspended(Institution $institution, ?DateTimeImmutable $now = null): bool
    {
        if (!$this->settings->bool('billing.suspend_after_grace')) {
            return false;
        }
        $sub = $this->current($institution, $now);

        return $sub !== null && in_array($sub->status($now), [Subscription::EXPIRED, Subscription::CANCELLED], true);
    }

    public function taxPercent(): float
    {
        return max(0.0, min(100.0, (float) $this->settings->get('billing.tax_percent', 0)));
    }

    // ------------------------------------------------------------------ internals

    private function renewalOpen(Subscription $sub, DateTimeImmutable $now): bool
    {
        return $now >= $sub->getPeriodEnd()->modify('-' . self::RENEWAL_WINDOW_DAYS . ' days');
    }

    /** The billing period to use: the one asked for if the plan offers it, otherwise the fallback if offered, otherwise the plan's own. */
    private function chosenInterval(SubscriptionPlan $plan, ?string $asked, string $fallback): ?string
    {
        $offered = $plan->intervalsOffered();
        if ($asked !== null && $asked !== '') {
            return in_array($asked, $offered, true) ? $asked : null;
        }

        return in_array($fallback, $offered, true) ? $fallback : $plan->getInterval();
    }

    /** A full term of a plan, starting on a given day. */
    private function termQuote(SubscriptionPlan $plan, string $kind, string $order, DateTimeImmutable $start, DateTimeImmutable $now, array $notes): array
    {
        $fee = (int) $plan->priceFor($kind);
        $end = $plan->periodEndFrom($start, $kind);
        $label = self::INTERVAL_LABEL[$kind] ?? $kind;

        return $this->totals([
            'ok' => true,
            'kind' => $order,
            'pay_now' => true,
            'plan_id' => $plan->getId(),
            'plan' => $plan->getName(),
            'interval' => $kind,
            'period_start' => $start->format(DATE_ATOM),
            'period_end' => $end->format(DATE_ATOM),
            'effective_on' => $start->format(DATE_ATOM),
            'lines' => [[
                'label' => sprintf('%s plan, one %s (%s to %s)', $plan->getName(), $label, $start->format('j M Y'), $end->format('j M Y')),
                'amount_kobo' => $fee,
            ]],
            'notes' => $notes,
        ]);
    }

    /** The old-to-new fee difference for the same kind of term, times unused days over total days in that term. */
    private function upgradeQuote(Subscription $sub, SubscriptionPlan $plan, int $oldFee, int $newFee, DateTimeImmutable $now): array
    {
        $unused = $sub->unusedDays($now);
        $total = $sub->termDays();
        $label = self::INTERVAL_LABEL[$sub->getBillingInterval()] ?? $sub->getBillingInterval();
        $lines = [[
            'label' => sprintf(
                'Upgrade from %s to %s for the rest of this %s: %s difference × %d of %d days',
                $sub->getPlan()->getName(), $plan->getName(), $label, $this->naira($newFee - $oldFee), $unused, $total,
            ),
            'amount_kobo' => (int) round(($newFee - $oldFee) * $unused / $total),
        ]];
        $notes = [sprintf('The %s plan and its larger limits apply as soon as the payment is verified. Your term still ends on %s.', $plan->getName(), $sub->getPeriodEnd()->format('j F Y'))];

        // A term already paid ahead on the smaller plan is topped up in full.
        if ($sub->hasPaidAhead()) {
            $nextKind = $sub->getNextInterval() ?? $sub->getBillingInterval();
            $nextOld = ($sub->getNextPlan() ?? $sub->getPlan())->priceFor($nextKind);
            $nextNew = $plan->priceFor($nextKind);
            if ($nextOld !== null && $nextNew !== null && $nextNew > $nextOld) {
                $lines[] = [
                    'label' => sprintf('Difference for the next %s, already paid on the smaller plan (to %s)', self::INTERVAL_LABEL[$nextKind] ?? $nextKind, $sub->paidUntil()->format('j M Y')),
                    'amount_kobo' => $nextNew - $nextOld,
                ];
            }
        }

        return $this->totals([
            'ok' => true,
            'kind' => 'upgrade',
            'pay_now' => true,
            'plan_id' => $plan->getId(),
            'plan' => $plan->getName(),
            'interval' => $sub->getBillingInterval(),
            'period_start' => $now->format(DATE_ATOM),
            'period_end' => $sub->getPeriodEnd()->format(DATE_ATOM),
            'effective_on' => $now->format(DATE_ATOM),
            'lines' => $lines,
            'notes' => $notes,
        ]);
    }

    /** Add the subtotal, any tax, and the one total that is payable. */
    private function totals(array $quote): array
    {
        $subtotal = array_sum(array_map(static fn (array $l): int => (int) $l['amount_kobo'], $quote['lines']));
        $percent = $this->taxPercent();
        $tax = (int) round($subtotal * $percent / 100);

        return $quote + [
            'subtotal_kobo' => $subtotal,
            'tax_percent' => $percent,
            'tax_kobo' => $tax,
            'total_kobo' => $subtotal + $tax,
            'total_naira' => ($subtotal + $tax) / 100,
        ];
    }

    private function naira(int $kobo): string
    {
        return '₦' . number_format($kobo / 100, $kobo % 100 === 0 ? 0 : 2);
    }
}
