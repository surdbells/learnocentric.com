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
            'acknowledged' => $this->acknowledged,
            'acknowledged_at' => $this->acknowledgedAt?->format(DATE_ATOM),
            'created_at' => $this->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
