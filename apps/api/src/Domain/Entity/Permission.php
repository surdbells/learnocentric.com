<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A permission subject, e.g. "institution", "user", "curriculum_pack", "assessment".
 * The action flags (view/create/edit/approve/export/delete) live on RolePermission.
 */
#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(string $code, ?string $description = null)
    {
        $this->code = $code;
        $this->description = $description;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getDescription(): ?string { return $this->description; }

    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'description' => $this->description];
    }
}
