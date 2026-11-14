<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Paystack payment attempt for a plan. It doubles as the invoice (what was
 * ordered, for which dates, and the amount) and, once paid, the receipt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_transactions')]
#[ORM\HasLifecycleCallbacks]
class BillingTransaction
{
    use TimestampsTrait;

    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    /** What the payment is for. "subscription" is the label on payments made before kinds were recorded. */
    public const KINDS = ['subscription', 'new', 'renewal', 'upgrade'];

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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'initiated_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $initiatedBy = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $reference;

    /** The full amount payable, tax included, in kobo. */
    #[ORM\Column(name: 'amount_kobo', type: Types::INTEGER)]
    private int $amountKobo;

    /** The tax part of the amount, in kobo. */
    #[ORM\Column(name: 'tax_kobo', type: Types::INTEGER, options: ['default' => 0])]
    private int $taxKobo = 0;

    #[ORM\Column(length: 20, options: ['default' => 'subscription'])]
    private string $kind = 'subscription';

    #[ORM\Column(name: 'billing_interval', length: 20, nullable: true)]
    private ?string $billingInterval = null;

    /** The order as it was shown before payment: lines, dates, and what it changes. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\Column(length: 20)]
    private string $status = self::PENDING;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Institution $institution, SubscriptionPlan $plan, string $reference, int $amountKobo)
    {
        $this->institution = $institution;
        $this->plan = $plan;
        $this->reference = $reference;
        $this->amountKobo = $amountKobo;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstitution(): Institution { return $this->institution; }
    public function getPlan(): SubscriptionPlan { return $this->plan; }
    public function getInitiatedBy(): ?User { return $this->initiatedBy; }
    public function setInitiatedBy(?User $v): void { $this->initiatedBy = $v; }
    public function getReference(): string { return $this->reference; }
    public function getAmountKobo(): int { return $this->amountKobo; }
    public function getTaxKobo(): int { return $this->taxKobo; }
    public function setTaxKobo(int $v): void { $this->taxKobo = max(0, $v); }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $v): void { $this->kind = in_array($v, self::KINDS, true) ? $v : 'subscription'; }
    public function getBillingInterval(): ?string { return $this->billingInterval; }
    public function setBillingInterval(?string $v): void { $this->billingInterval = $v; }
    public function getDetails(): ?array { return $this->details; }
    public function setDetails(?array $v): void { $this->details = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function setChannel(?string $v): void { $this->channel = $v; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $v): void { $this->paidAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'institution' => $this->institution->getName(),
            'plan' => $this->plan->getName(),
            'reference' => $this->reference,
            'kind' => $this->kind,
            'interval' => $this->billingInterval,
            'amount_kobo' => $this->amountKobo,
            'amount_naira' => $this->amountKobo / 100,
            'tax_naira' => $this->taxKobo / 100,
            'details' => $this->details,
            'status' => $this->status,
            'channel' => $this->channel,
            'initiated_by' => $this->initiatedBy ? $this->initiatedBy->displayName() : null,
            'paid_at' => $this->paidAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
