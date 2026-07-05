<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A governed direct message between two users of the same institution (spec §16,
 * logged communication). Every message is persisted and audit-logged; who may
 * message whom is enforced by MessagingAction's role-pair rules.
 */
#[ORM\Entity]
#[ORM\Table(name: 'messages')]
#[ORM\Index(name: 'idx_msg_pair', columns: ['sender_id', 'recipient_id'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'sender_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recipient_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Institution $institution, User $sender, User $recipient, string $body)
    {
        $this->institution = $institution;
        $this->sender = $sender;
        $this->recipient = $recipient;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSender(): User { return $this->sender; }
    public function getRecipient(): User { return $this->recipient; }
    public function isRead(): bool { return $this->readAt !== null; }
    public function markRead(): void { $this->readAt ??= new \DateTimeImmutable(); }

    public function toArray(?int $selfId = null): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender->getId(),
            'recipient_id' => $this->recipient->getId(),
            'body' => $this->body,
            'mine' => $selfId !== null && $this->sender->getId() === $selfId,
            'read' => $this->readAt !== null,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
