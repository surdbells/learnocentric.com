<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A Paystack payment attempt for a plan, doubles as the invoice record. */
#[ORM\Entity]
#[ORM\Table(name: 'billing_transactions')]
#[ORM\HasLifecycleCallbacks]
class BillingTransaction
{
    use TimestampsTrait;

    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

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

    #[ORM\Column(name: 'amount_kobo', type: Types::INTEGER)]
    private int $amountKobo;

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
    public function setInitiatedBy(?User $v): void { $this->initiatedBy = $v; }
    public function getReference(): string { return $this->reference; }
    public function getAmountKobo(): int { return $this->amountKobo; }
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
            'amount_kobo' => $this->amountKobo,
            'amount_naira' => $this->amountKobo / 100,
            'status' => $this->status,
            'channel' => $this->channel,
            'initiated_by' => $this->initiatedBy ? $this->initiatedBy->getFirstName() . ' ' . $this->initiatedBy->getLastName() : null,
            'paid_at' => $this->paidAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
