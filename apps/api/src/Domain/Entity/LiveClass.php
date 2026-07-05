<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A scheduled virtual class backed by a Daily.co room. Students in the class
 * can join while it is live; attendance is recorded (spec §15).
 */
#[ORM\Entity]
#[ORM\Table(name: 'live_classes')]
#[ORM\HasLifecycleCallbacks]
class LiveClass
{
    use TimestampsTrait;

    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const ENDED = 'ended';
    public const CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'host_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $host = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT, options: ['default' => 45])]
    private int $durationMinutes = 45;

    #[ORM\Column(name: 'room_name', length: 200, nullable: true)]
    private ?string $roomName = null;

    #[ORM\Column(name: 'room_url', length: 500, nullable: true)]
    private ?string $roomUrl = null;

    #[ORM\Column(length: 20)]
    private string $status = self::SCHEDULED;

    public function __construct(Subject $subject, string $title, \DateTimeImmutable $scheduledAt)
    {
        $this->subject = $subject;
        $this->title = $title;
        $this->scheduledAt = $scheduledAt;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): Subject { return $this->subject; }
    public function getSchoolClass(): ?SchoolClass { return $this->schoolClass; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function getTopic(): ?Topic { return $this->topic; }
    public function setTopic(?Topic $v): void { $this->topic = $v; }
    public function getHost(): ?User { return $this->host; }
    public function setHost(?User $v): void { $this->host = $v; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getScheduledAt(): \DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(\DateTimeImmutable $v): void { $this->scheduledAt = $v; }
    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $v): void { $this->durationMinutes = max(5, $v); }
    public function getRoomName(): ?string { return $this->roomName; }
    public function setRoomName(?string $v): void { $this->roomName = $v; }
    public function getRoomUrl(): ?string { return $this->roomUrl; }
    public function setRoomUrl(?string $v): void { $this->roomUrl = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }

    /** Joinable while scheduled or live (not ended/cancelled). */
    public function isJoinable(): bool
    {
        return in_array($this->status, [self::SCHEDULED, self::LIVE], true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject->getId(),
            'subject' => $this->subject->getName(),
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'topic_id' => $this->topic?->getId(),
            'topic' => $this->topic?->getTitle(),
            'host_id' => $this->host?->getId(),
            'host' => $this->host ? $this->host->getFirstName() . ' ' . $this->host->getLastName() : null,
            'title' => $this->title,
            'scheduled_at' => $this->scheduledAt->format(DATE_ATOM),
            'duration_minutes' => $this->durationMinutes,
            'room_url' => $this->roomUrl,
            'status' => $this->status,
        ];
    }
}
