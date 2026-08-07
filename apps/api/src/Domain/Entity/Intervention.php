<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A targeted intervention for a struggling student (spec §16): a weak area is
 * flagged, assigned to a staff member with a due date, and tracked to outcome.
 */
#[ORM\Entity]
#[ORM\Table(name: 'interventions')]
#[ORM\Index(name: 'idx_intervention_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Intervention
{
    use TimestampsTrait;

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED = 'resolved';
    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::RESOLVED];
    public const PRIORITIES = ['low', 'medium', 'high'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subject $subject = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'raised_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $raisedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(length: 20)]
    private string $status = self::OPEN;

    #[ORM\Column(length: 10, options: ['default' => 'medium'])]
    private string $priority = 'medium';

    /** Free-text intervention type, e.g. "Small Group Remediation". */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $outcome = null;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct(User $student, string $reason)
    {
        $this->student = $student;
        $this->reason = $reason;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): User { return $this->student; }
    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(?Subject $v): void { $this->subject = $v; }
    public function getTopic(): ?Topic { return $this->topic; }
    public function setTopic(?Topic $v): void { $this->topic = $v; }
    public function setRaisedBy(?User $v): void { $this->raisedBy = $v; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $v): void { $this->assignedTo = $v; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $v): void { $this->reason = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = in_array($v, self::STATUSES, true) ? $v : self::OPEN; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $v): void { $this->priority = in_array($v, self::PRIORITIES, true) ? $v : 'medium'; }
    public function getType(): ?string { return $this->type; }
    public function setType(?string $v): void { $this->type = ($v === null || trim($v) === '') ? null : $v; }
    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $v): void { $this->progress = max(0, min(100, $v)); }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $v): void { $this->dueDate = $v; }
    public function setOutcome(?string $v): void { $this->outcome = $v; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $v): void { $this->resolvedAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'subject_id' => $this->subject?->getId(),
            'subject' => $this->subject?->getName(),
            'topic_id' => $this->topic?->getId(),
            'topic' => $this->topic?->getTitle(),
            'raised_by' => $this->raisedBy ? $this->raisedBy->getFirstName() . ' ' . $this->raisedBy->getLastName() : null,
            'assigned_to_id' => $this->assignedTo?->getId(),
            'assigned_to' => $this->assignedTo ? $this->assignedTo->getFirstName() . ' ' . $this->assignedTo->getLastName() : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'priority' => $this->priority,
            'type' => $this->type,
            'progress' => $this->progress,
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'outcome' => $this->outcome,
            'resolved_at' => $this->resolvedAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
