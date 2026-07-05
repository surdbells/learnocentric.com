<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An in-app notification for a user (spec §18). */
#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notification_user_read', columns: ['user_id', 'is_read'])]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Optional in-app deep link (e.g. /student/academics/feedback). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(name: 'is_read', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $read = false;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, string $type, string $title)
    {
        $this->user = $user;
        $this->type = $type;
        $this->title = $title;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $v): void { $this->message = $v; }
    public function getLink(): ?string { return $this->link; }
    public function setLink(?string $v): void { $this->link = $v; }
    public function isRead(): bool { return $this->read; }
    public function setRead(bool $v): void { $this->read = $v; }
    public function setReadAt(?\DateTimeImmutable $v): void { $this->readAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
            'read' => $this->read,
            'created_at' => $this->getCreatedAt()->format(DATE_ATOM),
            'read_at' => $this->readAt?->format(DATE_ATOM),
        ];
    }
}
