<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A targeted note a teacher sends a student — praise, a correction, or a
 * re-teach prompt (often off the back of a missed question). Closes the
 * feedback/misconception loop of the learner journey (spec §14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'feedback_notes')]
#[ORM\HasLifecycleCallbacks]
class FeedbackNote
{
    use TimestampsTrait;

    public const TYPES = ['praise', 'correction', 'reteach', 'general'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\Column(length: 20)]
    private string $type = 'general';

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** Structured feedback for parent reports (spec §7.5, §18) — all optional. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $strengths = null;

    #[ORM\Column(name: 'practice_needed', type: Types::TEXT, nullable: true)]
    private ?string $practiceNeeded = null;

    #[ORM\Column(name: 'parent_support_suggestion', type: Types::TEXT, nullable: true)]
    private ?string $parentSupportSuggestion = null;

    // --- Structured breakdown (design: Feedback_LD) — all optional ---

    /** Percentage score this feedback relates to. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(name: 'common_error', type: Types::TEXT, nullable: true)]
    private ?string $commonError = null;

    #[ORM\Column(name: 'next_step', type: Types::TEXT, nullable: true)]
    private ?string $nextStep = null;

    /** Teacher-rated focus areas: [{label, score}] (0–100). */
    #[ORM\Column(name: 'focus_areas', type: Types::JSON, nullable: true)]
    private ?array $focusAreas = null;

    /** What this feedback is about: quiz | worksheet | portfolio | general. */
    #[ORM\Column(name: 'source_type', length: 20, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(name: 'source_title', length: 200, nullable: true)]
    private ?string $sourceTitle = null;

    #[ORM\Column(name: 'subject_name', length: 120, nullable: true)]
    private ?string $subjectName = null;

    /** Optional marked-work file (stored path, served via /backend/files). */
    #[ORM\Column(name: 'attachment_url', length: 500, nullable: true)]
    private ?string $attachmentUrl = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $acknowledged = false;

    #[ORM\Column(name: 'acknowledged_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    public function __construct(User $student, string $message)
    {
        $this->student = $student;
        $this->message = $message;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): User { return $this->student; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $v): void { $this->author = $v; }
    public function getTopic(): ?Topic { return $this->topic; }
    public function setTopic(?Topic $v): void { $this->topic = $v; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = in_array($v, self::TYPES, true) ? $v : 'general'; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $v): void { $this->message = $v; }
    public function getStrengths(): ?string { return $this->strengths; }
    public function setStrengths(?string $v): void { $this->strengths = ($v === null || trim($v) === '') ? null : $v; }
    public function getPracticeNeeded(): ?string { return $this->practiceNeeded; }
    public function setPracticeNeeded(?string $v): void { $this->practiceNeeded = ($v === null || trim($v) === '') ? null : $v; }
    public function getParentSupportSuggestion(): ?string { return $this->parentSupportSuggestion; }
    public function setParentSupportSuggestion(?string $v): void { $this->parentSupportSuggestion = ($v === null || trim($v) === '') ? null : $v; }
    public function getScore(): ?int { return $this->score; }
    public function setScore(?int $v): void { $this->score = $v === null ? null : max(0, min(100, $v)); }
    public function getCommonError(): ?string { return $this->commonError; }
    public function setCommonError(?string $v): void { $this->commonError = ($v === null || trim($v) === '') ? null : $v; }
    public function getNextStep(): ?string { return $this->nextStep; }
    public function setNextStep(?string $v): void { $this->nextStep = ($v === null || trim($v) === '') ? null : $v; }
    public function getFocusAreas(): ?array { return $this->focusAreas; }
    public function setFocusAreas(?array $v): void { $this->focusAreas = ($v === null || $v === []) ? null : $v; }
    public function getSourceType(): ?string { return $this->sourceType; }
    public function setSourceType(?string $v): void { $this->sourceType = ($v === null || trim($v) === '') ? null : $v; }
    public function getSourceTitle(): ?string { return $this->sourceTitle; }
    public function setSourceTitle(?string $v): void { $this->sourceTitle = ($v === null || trim($v) === '') ? null : $v; }
    public function getSubjectName(): ?string { return $this->subjectName; }
    public function setSubjectName(?string $v): void { $this->subjectName = ($v === null || trim($v) === '') ? null : $v; }
    public function getAttachmentUrl(): ?string { return $this->attachmentUrl; }
    public function setAttachmentUrl(?string $v): void { $this->attachmentUrl = \App\Service\Storage\FilePath::toPath($v); }
    public function isAcknowledged(): bool { return $this->acknowledged; }
    public function setAcknowledged(bool $v): void { $this->acknowledged = $v; }
    public function setAcknowledgedAt(?\DateTimeImmutable $v): void { $this->acknowledgedAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'author' => $this->author ? $this->author->getFirstName() . ' ' . $this->author->getLastName() : null,
            'topic_id' => $this->topic?->getId(),
            'topic' => $this->topic?->getTitle(),
            'type' => $this->type,
            'message' => $this->message,
            'strengths' => $this->strengths,
            'practice_needed' => $this->practiceNeeded,
            'parent_support_suggestion' => $this->parentSupportSuggestion,
            'score' => $this->score,
            'common_error' => $this->commonError,
            'next_step' => $this->nextStep,
            'focus_areas' => $this->focusAreas,
            'source_type' => $this->sourceType,
            'source_title' => $this->sourceTitle,
            'subject' => $this->subjectName,
            'attachment_url' => \App\Service\Storage\FilePath::toUrl($this->attachmentUrl),
            'acknowledged' => $this->acknowledged,
            'acknowledged_at' => $this->acknowledgedAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
