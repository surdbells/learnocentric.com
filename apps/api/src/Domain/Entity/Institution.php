<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'institutions')]
#[ORM\HasLifecycleCallbacks]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 50, options: ['default' => 'school'])]
    private string $type = 'school';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'logo_url', length: 1024, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $branding = null;

    #[ORM\Column(name: 'admin_contact', type: Types::JSON, nullable: true)]
    private ?array $adminContact = null;

    #[ORM\Column(name: 'subscription_id', nullable: true)]
    private ?int $subscriptionId = null;

    #[ORM\Column(name: 'assigned_package_id', nullable: true)]
    private ?int $assignedPackageId = null;

    #[ORM\Column(length: 30, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): void { $this->type = $type; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): void { $this->address = $address; }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): void { $this->logoUrl = $logoUrl; }
    public function getBranding(): ?array { return $this->branding; }
    public function setBranding(?array $branding): void { $this->branding = $branding; }
    public function getAdminContact(): ?array { return $this->adminContact; }
    public function setAdminContact(?array $adminContact): void { $this->adminContact = $adminContact; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getAssignedPackageId(): ?int { return $this->assignedPackageId; }
    public function setAssignedPackageId(?int $id): void { $this->assignedPackageId = $id; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'logo_url' => $this->logoUrl,
            'branding' => $this->branding,
            'admin_contact' => $this->adminContact,
            'subscription_id' => $this->subscriptionId,
            'assigned_package_id' => $this->assignedPackageId,
            'status' => $this->status,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
