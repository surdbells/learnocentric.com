<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece of portfolio evidence a student submits for a topic's Competency
 * Transfer track (spec §2). Reviewed qualitatively (a competency rating, not a
 * numeric score) so it stays separate from academic marks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portfolio_entries')]
#[ORM\HasLifecycleCallbacks]
class PortfolioEntry
{
    use TimestampsTrait;

    public const SUBMITTED = 'submitted';
    public const REVIEWED = 'reviewed';

    /** Qualitative competency ratings, low → high. */
    public const RATINGS = ['emerging', 'developing', 'proficient', 'mastery'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Optional file or external link evidencing the competency. */
    #[ORM\Column(name: 'evidence_url', length: 500, nullable: true)]
    private ?string $evidenceUrl = null;

    #[ORM\Column(length: 20)]
    private string $status = self::SUBMITTED;

    #[ORM\Column(name: 'competency_rating', length: 20, nullable: true)]
    private ?string $competencyRating = null;

    #[ORM\Column(name: 'reviewer_feedback', type: Types::TEXT, nullable: true)]
    private ?string $reviewerFeedback = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(Topic $topic, User $student, string $title, string $description)
    {
        $this->topic = $topic;
        $this->student = $student;
        $this->title = $title;
        $this->description = $description;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): Topic { return $this->topic; }
    public function getStudent(): User { return $this->student; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): void { $this->description = $v; }
    public function getEvidenceUrl(): ?string { return $this->evidenceUrl; }
    public function setEvidenceUrl(?string $v): void { $this->evidenceUrl = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getCompetencyRating(): ?string { return $this->competencyRating; }
    public function setCompetencyRating(?string $v): void { $this->competencyRating = $v; }
    public function setReviewerFeedback(?string $v): void { $this->reviewerFeedback = $v; }
    public function setReviewedBy(?User $v): void { $this->reviewedBy = $v; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $v): void { $this->submittedAt = $v; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $v): void { $this->reviewedAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic->getId(),
            'topic' => $this->topic->getTitle(),
            'subject_id' => $this->topic->getSubject()->getId(),
            'subject' => $this->topic->getSubject()->getName(),
            'competency_expected' => $this->topic->toArray()['portfolio_evidence_expected'],
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'title' => $this->title,
            'description' => $this->description,
            'evidence_url' => $this->evidenceUrl,
            'status' => $this->status,
            'competency_rating' => $this->competencyRating,
            'reviewer_feedback' => $this->reviewerFeedback,
            'reviewed_by' => $this->reviewedBy ? $this->reviewedBy->getFirstName() . ' ' . $this->reviewedBy->getLastName() : null,
            'submitted_at' => $this->submittedAt?->format(DATE_ATOM),
            'reviewed_at' => $this->reviewedAt?->format(DATE_ATOM),
        ];
    }
}
