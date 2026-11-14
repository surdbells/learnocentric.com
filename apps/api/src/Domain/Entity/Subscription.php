<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An organisation's subscription to a plan. There is one per organisation; it
 * records the term currently paid for, and optionally the next one when the
 * renewal was paid early.
 *
 * Access is computed, not stored. Everything is timed from ONE reference date,
 * the last paid platform day (paidUntil()):
 *
 *   up to the last paid day      active
 *   days 1 to 7 after it         grace      (a failed or late renewal; not for a cancellation)
 *   day 8 onwards                expired    (tutors and learners are suspended)
 *   90 days after it             the files and records become eligible for deletion
 *
 * A customer who cancels keeps access to the last paid day and then goes
 * straight to "expired": the grace period is for failed renewals only.
 * Nothing in this class deletes anything.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    use TimestampsTrait;

    public const GRACE_DAYS = 7;
    /** How long files and learning records are kept after the last paid day. */
    public const RETENTION_DAYS = 90;
    /** How long before the last paid day the payer is reminded. */
    public const REMINDER_DAYS = 7;

    public const ACTIVE = 'active';
    public const GRACE = 'grace';
    /** Past the grace period: tutor and learner access is suspended. */
    public const EXPIRED = 'expired';
    /** Ended by the platform (not the customer's own "stop renewal"). */
    public const CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private SubscriptionPlan $plan;

    #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodEnd;

    /** The kind of term paid for (termly, yearly, monthly, cohort). Null on older rows: the plan's standard period. */
    #[ORM\Column(name: 'billing_interval', length: 20, nullable: true)]
    private ?string $billingInterval = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $cancelled = false;

    /** The customer has stopped renewal: access runs to the last paid day, then ends with no grace. */
    #[ORM\Column(name: 'cancel_at_period_end', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** A change the customer asked for at the next renewal (a downgrade); not yet paid for. */
    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(name: 'pending_plan_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SubscriptionPlan $pendingPlan = null;

    #[ORM\Column(name: 'pending_interval', length: 20, nullable: true)]
    private ?string $pendingInterval = null;

    /** The next term, when its renewal has already been paid: it begins when the current one ends. */
    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(name: 'next_plan_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SubscriptionPlan $nextPlan = null;

    #[ORM\Column(name: 'next_interval', length: 20, nullable: true)]
    private ?string $nextInterval = null;

    #[ORM\Column(name: 'next_period_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextPeriodEnd = null;

    /** Reminders already sent for the current last paid day: {for: "Y-m-d", sent: {key: time}}. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notices = null;

    public function __construct(Institution $institution, SubscriptionPlan $plan, \DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        $this->institution = $institution;
        $this->plan = $plan;
        $this->periodStart = $start;
        $this->periodEnd = $end;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstitution(): Institution { return $this->institution; }
    public function getPlan(): SubscriptionPlan { return $this->plan; }
    public function setPlan(SubscriptionPlan $v): void { $this->plan = $v; }
    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $v): void { $this->periodStart = $v; }
    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $v): void { $this->periodEnd = $v; }
    public function isCancelled(): bool { return $this->cancelled; }
    public function setCancelled(bool $v): void { $this->cancelled = $v; }

    /** The kind of term currently paid for. */
    public function getBillingInterval(): string { return $this->billingInterval ?? $this->plan->getInterval(); }
    public function setBillingInterval(?string $v): void { $this->billingInterval = $v; }

    public function isCancelAtPeriodEnd(): bool { return $this->cancelAtPeriodEnd; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }

    /** The customer stops future renewal. Access continues to the last paid day. */
    public function stopRenewal(?\DateTimeImmutable $now = null): void
    {
        $this->cancelAtPeriodEnd = true;
        $this->cancelledAt = $now ?? new \DateTimeImmutable();
        $this->pendingPlan = null;
        $this->pendingInterval = null;
    }

    public function resumeRenewal(): void
    {
        $this->cancelAtPeriodEnd = false;
        $this->cancelledAt = null;
    }

    public function getPendingPlan(): ?SubscriptionPlan { return $this->pendingPlan; }
    public function getPendingInterval(): ?string { return $this->pendingInterval; }
    public function setPendingChange(?SubscriptionPlan $plan, ?string $interval): void
    {
        $this->pendingPlan = $plan;
        $this->pendingInterval = $plan === null ? null : $interval;
    }

    public function getNextPlan(): ?SubscriptionPlan { return $this->nextPlan; }
    public function getNextInterval(): ?string { return $this->nextInterval; }
    public function getNextPeriodEnd(): ?\DateTimeImmutable { return $this->nextPeriodEnd; }
    public function hasPaidAhead(): bool { return $this->nextPeriodEnd !== null; }

    /** Record a renewal paid before the current term ended: it starts when this one ends. */
    public function setPaidAhead(SubscriptionPlan $plan, string $interval, \DateTimeImmutable $until): void
    {
        $this->nextPlan = $plan;
        $this->nextInterval = $interval;
        $this->nextPeriodEnd = $until;
    }

    public function setNextPlan(SubscriptionPlan $plan): void { $this->nextPlan = $plan; }

    /**
     * Start the term that was paid ahead once the current one is over.
     * Returns true when it moved on (the caller saves).
     */
    public function rollOver(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        if ($this->nextPeriodEnd === null || $now <= $this->periodEnd) {
            return false;
        }
        $this->periodStart = $this->periodEnd;
        $this->periodEnd = $this->nextPeriodEnd;
        if ($this->nextPlan !== null) {
            $this->plan = $this->nextPlan;
        }
        $this->billingInterval = $this->nextInterval ?? $this->billingInterval;
        $this->nextPlan = null;
        $this->nextInterval = null;
        $this->nextPeriodEnd = null;

        return true;
    }

    /** The last paid platform day: the single reference date for grace, suspension and retention. */
    public function paidUntil(): \DateTimeImmutable
    {
        return $this->nextPeriodEnd ?? $this->periodEnd;
    }

    public function graceUntil(): \DateTimeImmutable
    {
        return $this->paidUntil()->modify('+' . self::GRACE_DAYS . ' days');
    }

    /** Until when the organisation's files and learning records are kept after the last paid day. */
    public function retentionUntil(): \DateTimeImmutable
    {
        return $this->paidUntil()->modify('+' . self::RETENTION_DAYS . ' days');
    }

    /** Computed access state right now. */
    public function status(?\DateTimeImmutable $now = null): string
    {
        if ($this->cancelled) {
            return self::CANCELLED;
        }
        $now ??= new \DateTimeImmutable();
        if ($now <= $this->paidUntil()) {
            return self::ACTIVE;
        }
        // A customer who chose to stop gets no grace: that period is for failed renewals.
        if (!$this->cancelAtPeriodEnd && $now <= $this->graceUntil()) {
            return self::GRACE;
        }

        return self::EXPIRED;
    }

    /** Past the retention period: the data may be deleted by a deliberate decision. Nothing does so automatically. */
    public function isDeletionEligible(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) > $this->retentionUntil();
    }

    /** Whole days in the current term (at least one). */
    public function termDays(): int
    {
        return max(1, (int) round(($this->periodEnd->getTimestamp() - $this->periodStart->getTimestamp()) / 86400));
    }

    /** Days of the current term not yet used: all of them before it starts, none after it ends. */
    public function unusedDays(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        if ($now <= $this->periodStart) {
            return $this->termDays();
        }
        if ($now >= $this->periodEnd) {
            return 0;
        }

        return min($this->termDays(), (int) ceil(($this->periodEnd->getTimestamp() - $now->getTimestamp()) / 86400));
    }

    /** Has this reminder already gone out for the current last paid day? */
    public function noticeSent(string $key): bool
    {
        return ($this->notices['for'] ?? null) === $this->paidUntil()->format('Y-m-d')
            && isset($this->notices['sent'][$key]);
    }

    public function markNotice(string $key, ?\DateTimeImmutable $now = null): void
    {
        $for = $this->paidUntil()->format('Y-m-d');
        $sent = ($this->notices['for'] ?? null) === $for ? ($this->notices['sent'] ?? []) : [];
        $sent[$key] = ($now ?? new \DateTimeImmutable())->format(DATE_ATOM);
        $this->notices = ['for' => $for, 'sent' => $sent];
    }

    public function toArray(): array
    {
        $now = new \DateTimeImmutable();
        $status = $this->status($now);
        $paidUntil = $this->paidUntil();

        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'institution' => $this->institution->getName(),
            'plan_id' => $this->plan->getId(),
            'plan' => $this->plan->getName(),
            'plan_code' => $this->plan->getCode(),
            'interval' => $this->getBillingInterval(),
            'period_start' => $this->periodStart->format(DATE_ATOM),
            'period_end' => $this->periodEnd->format(DATE_ATOM),
            'paid_until' => $paidUntil->format(DATE_ATOM),
            'grace_until' => $this->graceUntil()->format(DATE_ATOM),
            'retention_until' => $this->retentionUntil()->format(DATE_ATOM),
            'deletion_eligible' => $this->isDeletionEligible($now),
            'status' => $status,
            'days_left' => (int) floor(($paidUntil->getTimestamp() - $now->getTimestamp()) / 86400),
            'renewal_stopped' => $this->cancelAtPeriodEnd,
            'renewal_stopped_at' => $this->cancelledAt?->format(DATE_ATOM),
            'pending_change' => $this->pendingPlan === null ? null : [
                'plan_id' => $this->pendingPlan->getId(),
                'plan' => $this->pendingPlan->getName(),
                'interval' => $this->pendingInterval ?? $this->pendingPlan->getInterval(),
            ],
            'paid_ahead' => $this->nextPeriodEnd === null ? null : [
                'plan_id' => ($this->nextPlan ?? $this->plan)->getId(),
                'plan' => ($this->nextPlan ?? $this->plan)->getName(),
                'interval' => $this->nextInterval ?? $this->getBillingInterval(),
                'starts' => $this->periodEnd->format(DATE_ATOM),
                'ends' => $this->nextPeriodEnd->format(DATE_ATOM),
            ],
        ];
    }
}
