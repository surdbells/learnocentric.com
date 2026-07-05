<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A support request raised by an institution user and worked by the platform
 * support team (spec §17, Support centre). Carries an SLA derived from priority,
 * an escalation flag, and a threaded message history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_tickets')]
#[ORM\HasLifecycleCallbacks]
class SupportTicket
{
    use TimestampsTrait;

    public const CATEGORIES = ['technical', 'billing', 'content', 'account', 'other'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const WAITING = 'waiting';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::WAITING, self::RESOLVED, self::CLOSED];

    /** First-response SLA target in hours, by priority. */
    private const SLA_HOURS = ['urgent' => 4, 'high' => 8, 'normal' => 24, 'low' => 72];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $reference;

    #[ORM\Column(length: 200)]
    private string $subject;

    #[ORM\Column(length: 20)]
    private string $category = 'technical';

    #[ORM\Column(length: 20)]
    private string $priority = 'normal';

    #[ORM\Column(length: 20)]
    private string $status = self::OPEN;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $escalated = false;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(name: 'first_response_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstResponseAt = null;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var Collection<int, SupportMessage> */
    #[ORM\OneToMany(mappedBy: 'ticket', targetEntity: SupportMessage::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(string $subject, User $createdBy, string $reference)
    {
        $this->subject = $subject;
        $this->createdBy = $createdBy;
        $this->reference = $reference;
        $this->institution = $createdBy->getInstitution();
        $this->messages = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getInstitution(): ?Institution { return $this->institution; }
    public function getStatus(): string { return $this->status; }
    public function setCategory(string $v): void { $this->category = in_array($v, self::CATEGORIES, true) ? $v : 'other'; }
    public function setPriority(string $v): void { $this->priority = in_array($v, self::PRIORITIES, true) ? $v : 'normal'; }
    public function setSubject(string $v): void { $this->subject = $v; }
    public function setAssignedTo(?User $v): void { $this->assignedTo = $v; }
    public function escalate(): void { $this->escalated = true; if ($this->priority !== 'urgent') { $this->priority = 'high'; } }

    public function getMessages(): Collection { return $this->messages; }
    public function addMessage(SupportMessage $m): void { $this->messages->add($m); }

    /** Record that the support team has responded (stops the first-response SLA clock). */
    public function markStaffResponded(): void
    {
        if ($this->firstResponseAt === null) {
            $this->firstResponseAt = new \DateTimeImmutable();
        }
        if ($this->status === self::OPEN) {
            $this->status = self::IN_PROGRESS;
        }
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $this->status = $status;
        if (($status === self::RESOLVED || $status === self::CLOSED) && $this->resolvedAt === null) {
            $this->resolvedAt = new \DateTimeImmutable();
        }
        if ($status === self::OPEN || $status === self::IN_PROGRESS) {
            $this->resolvedAt = null;
        }
    }

    public function slaDueAt(): \DateTimeImmutable
    {
        return $this->createdAt->modify('+' . (self::SLA_HOURS[$this->priority] ?? 24) . ' hours');
    }

    /** Overdue only while still awaiting a first response. */
    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if ($this->firstResponseAt !== null) {
            return false;
        }
        return ($now ?? new \DateTimeImmutable()) > $this->slaDueAt();
    }

    public function toArray(bool $withMessages = false): array
    {
        $out = [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'escalated' => $this->escalated,
            'institution' => $this->institution?->getName(),
            'created_by' => trim($this->createdBy->getFirstName() . ' ' . $this->createdBy->getLastName()),
            'created_by_id' => $this->createdBy->getId(),
            'assigned_to' => $this->assignedTo ? trim($this->assignedTo->getFirstName() . ' ' . $this->assignedTo->getLastName()) : null,
            'sla_due_at' => $this->slaDueAt()->format(DATE_ATOM),
            'first_response_at' => $this->firstResponseAt?->format(DATE_ATOM),
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
            'overdue' => $this->isOverdue(),
            'message_count' => $this->messages->count(),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
        if ($withMessages) {
            $out['messages'] = $this->messages->map(fn (SupportMessage $m) => $m->toArray())->getValues();
        }
        return $out;
    }
}
