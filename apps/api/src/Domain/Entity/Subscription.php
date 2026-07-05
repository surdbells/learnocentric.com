<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An institution's subscription to a plan. Access is computed, not stored:
 * active while inside the paid period, then a grace window, then expired.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    use TimestampsTrait;

    public const GRACE_DAYS = 7;

    public const ACTIVE = 'active';
    public const GRACE = 'grace';
    public const EXPIRED = 'expired';
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

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $cancelled = false;

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

    public function graceUntil(): \DateTimeImmutable
    {
        return $this->periodEnd->modify('+' . self::GRACE_DAYS . ' days');
    }

    /** Computed access state right now. */
    public function status(?\DateTimeImmutable $now = null): string
    {
        if ($this->cancelled) {
            return self::CANCELLED;
        }
        $now ??= new \DateTimeImmutable();
        if ($now <= $this->periodEnd) {
            return self::ACTIVE;
        }
        if ($now <= $this->graceUntil()) {
            return self::GRACE;
        }
        return self::EXPIRED;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'institution' => $this->institution->getName(),
            'plan_id' => $this->plan->getId(),
            'plan' => $this->plan->getName(),
            'plan_code' => $this->plan->getCode(),
            'interval' => $this->plan->getInterval(),
            'period_start' => $this->periodStart->format(DATE_ATOM),
            'period_end' => $this->periodEnd->format(DATE_ATOM),
            'grace_until' => $this->graceUntil()->format(DATE_ATOM),
            'status' => $this->status(),
        ];
    }
}
