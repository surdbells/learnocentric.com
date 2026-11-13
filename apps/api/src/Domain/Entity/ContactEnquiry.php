<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message sent through the public "Contact us" form. Kept so nothing is
 * lost when email isn't configured; the platform owner reads them in the
 * Support Centre. The sender's IP is stored only as a salted hash, for
 * rate limiting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contact_enquiries')]
#[ORM\Index(name: 'idx_contact_enquiries_created', columns: ['created_at'])]
class ContactEnquiry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $organisation = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(name: 'ip_hash', length: 64)]
    private string $ipHash;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $emailed = false;

    #[ORM\Column(name: 'handled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $handledAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email, string $message, string $ipHash)
    {
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->ipHash = $ipHash;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getMessage(): string { return $this->message; }
    public function getOrganisation(): ?string { return $this->organisation; }
    public function setOrganisation(?string $v): void { $this->organisation = $v; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $v): void { $this->role = $v; }
    public function setEmailed(bool $v): void { $this->emailed = $v; }
    public function setHandled(bool $v): void { $this->handledAt = $v ? ($this->handledAt ?? new DateTimeImmutable()) : null; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'organisation' => $this->organisation,
            'role' => $this->role,
            'message' => $this->message,
            'emailed' => $this->emailed,
            'handled' => $this->handledAt !== null,
            'handled_at' => $this->handledAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
