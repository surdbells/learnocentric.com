<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A safeguarding concern (spec §20). Restricted: any staff can report one, but
 * only designated leads (institution admins) may view or manage the case.
 */
#[ORM\Entity]
#[ORM\Table(name: 'safeguarding_cases')]
#[ORM\Index(name: 'idx_safeguarding_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class SafeguardingCase
{
    use TimestampsTrait;

    public const CATEGORIES = ['welfare', 'bullying', 'abuse', 'attendance', 'mental_health', 'other'];

    public const REPORTED = 'reported';
    public const UNDER_REVIEW = 'under_review';
    public const ESCALATED = 'escalated';
    public const CLOSED = 'closed';
    public const STATUSES = [self::REPORTED, self::UNDER_REVIEW, self::ESCALATED, self::CLOSED];

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITIES = [self::SEVERITY_LOW, self::SEVERITY_MEDIUM, self::SEVERITY_HIGH, self::SEVERITY_CRITICAL];

    public const ACCESS_STANDARD = 'standard';
    public const ACCESS_RESTRICTED = 'restricted';
    public const ACCESS_LEVELS = [self::ACCESS_STANDARD, self::ACCESS_RESTRICTED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** The student the concern is about (optional, a concern may be general). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reported_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reportedBy = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution = null;

    #[ORM\Column(length: 30)]
    private string $category = 'welfare';

    #[ORM\Column(length: 200)]
    private string $summary;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 20)]
    private string $status = self::REPORTED;

    /** How urgent the concern is (spec §15). */
    #[ORM\Column(length: 12, options: ['default' => self::SEVERITY_MEDIUM])]
    private string $severity = self::SEVERITY_MEDIUM;

    /** Whether the case needs tighter, lead-only handling. */
    #[ORM\Column(name: 'access_level', length: 12, options: ['default' => self::ACCESS_STANDARD])]
    private string $accessLevel = self::ACCESS_STANDARD;

    /**
     * Optional attachment references, each shaped {label, url}.
     *
     * @var array<int, array{label: string, url: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $evidence = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'handled_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $outcome = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(string $summary)
    {
        $this->summary = $summary;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): ?User { return $this->student; }
    public function setStudent(?User $v): void { $this->student = $v; }
    public function getReportedBy(): ?User { return $this->reportedBy; }
    public function setReportedBy(?User $v): void { $this->reportedBy = $v; }
    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $v): void { $this->institution = $v; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): void { $this->category = in_array($v, self::CATEGORIES, true) ? $v : 'other'; }
    public function getSummary(): string { return $this->summary; }
    public function setSummary(string $v): void { $this->summary = $v; }
    public function setDetails(?string $v): void { $this->details = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = in_array($v, self::STATUSES, true) ? $v : self::REPORTED; }
    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $v): void { $this->severity = in_array($v, self::SEVERITIES, true) ? $v : self::SEVERITY_MEDIUM; }
    public function getAccessLevel(): string { return $this->accessLevel; }
    public function setAccessLevel(string $v): void { $this->accessLevel = in_array($v, self::ACCESS_LEVELS, true) ? $v : self::ACCESS_STANDARD; }
    /** @return array<int, array{label: string, url: string}>|null */
    public function getEvidence(): ?array { return $this->evidence; }
    /** @param array<int, array{label: string, url: string}>|null $v */
    public function setEvidence(?array $v): void { $this->evidence = $v === [] ? null : $v; }
    public function setHandledBy(?User $v): void { $this->handledBy = $v; }
    public function setOutcome(?string $v): void { $this->outcome = $v; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $v): void { $this->closedAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student?->getId(),
            'student' => $this->student ? $this->student->getFirstName() . ' ' . $this->student->getLastName() : null,
            'reported_by' => $this->reportedBy ? $this->reportedBy->getFirstName() . ' ' . $this->reportedBy->getLastName() : null,
            'category' => $this->category,
            'summary' => $this->summary,
            'details' => $this->details,
            'status' => $this->status,
            'severity' => $this->severity,
            'access_level' => $this->accessLevel,
            'evidence' => $this->evidence,
            'handled_by' => $this->handledBy ? $this->handledBy->getFirstName() . ' ' . $this->handledBy->getLastName() : null,
            'outcome' => $this->outcome,
            'closed_at' => $this->closedAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
