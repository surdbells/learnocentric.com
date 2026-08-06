<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A school announcement broadcast to an audience within an institution
 * (spec §16). Posted by staff; read by the targeted audience. Carries the
 * Communication-hub metadata: category, priority, delivery channels, optional
 * class/subject targeting, scheduling, and a draft→scheduled→sent lifecycle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'announcements')]
#[ORM\HasLifecycleCallbacks]
class Announcement
{
    use TimestampsTrait;

    /** Audience segments; 'all' means everyone, 'class' targets a specific class. */
    public const AUDIENCES = ['all', 'students', 'teachers', 'parents', 'staff', 'class'];
    public const CATEGORIES = ['general', 'academics', 'events', 'internal', 'attendance', 'reminder'];
    public const PRIORITIES = ['low', 'medium', 'high'];
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const SENT = 'sent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 20)]
    private string $audience = 'all';

    #[ORM\Column(length: 20, options: ['default' => 'general'])]
    private string $category = 'general';

    #[ORM\Column(length: 10, options: ['default' => 'medium'])]
    private string $priority = 'medium';

    #[ORM\Column(length: 12, options: ['default' => 'sent'])]
    private string $status = 'sent';

    /** Optional class targeting when audience = 'class'. */
    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    #[ORM\Column(name: 'subject_name', length: 120, nullable: true)]
    private ?string $subjectName = null;

    /** Delivery channels: {in_app, email, parent_copy}. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $channels = null;

    #[ORM\Column(name: 'attachment_url', length: 500, nullable: true)]
    private ?string $attachmentUrl = null;

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'recipient_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $recipientCount = 0;

    public function __construct(Institution $institution, ?User $author, string $title, string $body, string $audience = 'all')
    {
        $this->institution = $institution;
        $this->author = $author;
        $this->title = $title;
        $this->body = $body;
        $this->audience = in_array($audience, self::AUDIENCES, true) ? $audience : 'all';
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getAudience(): string { return $this->audience; }
    public function setAudience(string $v): void { $this->audience = in_array($v, self::AUDIENCES, true) ? $v : 'all'; }
    public function getInstitution(): Institution { return $this->institution; }
    public function getAuthor(): ?User { return $this->author; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $v): void { $this->body = $v; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): void { $this->category = in_array($v, self::CATEGORIES, true) ? $v : 'general'; }
    public function getPriority(): string { return $this->priority; }
    public function setPriority(string $v): void { $this->priority = in_array($v, self::PRIORITIES, true) ? $v : 'medium'; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = in_array($v, [self::DRAFT, self::SCHEDULED, self::SENT], true) ? $v : self::SENT; }
    public function getSchoolClass(): ?SchoolClass { return $this->schoolClass; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function getSubjectName(): ?string { return $this->subjectName; }
    public function setSubjectName(?string $v): void { $this->subjectName = ($v === null || trim($v) === '') ? null : $v; }
    public function getChannels(): ?array { return $this->channels; }
    public function setChannels(?array $v): void { $this->channels = $v; }
    public function getAttachmentUrl(): ?string { return $this->attachmentUrl; }
    public function setAttachmentUrl(?string $v): void { $this->attachmentUrl = \App\Service\Storage\FilePath::toPath($v); }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $v): void { $this->scheduledAt = $v; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $v): void { $this->sentAt = $v; }
    public function getRecipientCount(): int { return $this->recipientCount; }
    public function setRecipientCount(int $v): void { $this->recipientCount = $v; }

    /** Roles that a given audience segment resolves to. */
    public static function rolesForAudience(string $audience): array
    {
        return match ($audience) {
            'students', 'class' => ['student'],
            'teachers' => ['teacher'],
            'parents' => ['parent'],
            'staff' => ['school_admin', 'tutor_admin', 'teacher'],
            default => ['student', 'teacher', 'parent', 'school_admin', 'tutor_admin'],
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'subject' => $this->subjectName,
            'channels' => $this->channels ?? ['in_app' => true, 'email' => false, 'parent_copy' => false],
            'attachment_url' => \App\Service\Storage\FilePath::toUrl($this->attachmentUrl),
            'scheduled_at' => $this->scheduledAt?->format(DATE_ATOM),
            'sent_at' => $this->sentAt?->format(DATE_ATOM),
            'recipient_count' => $this->recipientCount,
            'author' => $this->author ? trim($this->author->getFirstName() . ' ' . $this->author->getLastName()) : 'School',
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
