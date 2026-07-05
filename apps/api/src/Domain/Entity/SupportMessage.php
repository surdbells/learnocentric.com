<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One message in a support ticket thread. */
#[ORM\Entity]
#[ORM\Table(name: 'support_messages')]
class SupportMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupportTicket::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupportTicket $ticket;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** True when written by the support team rather than the requester. */
    #[ORM\Column(name: 'is_staff', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isStaff = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(SupportTicket $ticket, ?User $author, string $body, bool $isStaff)
    {
        $this->ticket = $ticket;
        $this->author = $author;
        $this->body = $body;
        $this->isStaff = $isStaff;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_staff' => $this->isStaff,
            'author' => $this->author ? trim($this->author->getFirstName() . ' ' . $this->author->getLastName()) : 'Removed user',
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
