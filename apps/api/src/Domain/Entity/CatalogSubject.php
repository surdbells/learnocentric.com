<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A platform-wide subject in the SaaS catalogue, owned by the super admin.
 * Schools adopt catalogue subjects into their own Subject list, and content
 * packages are scoped to catalogue subjects — so a package's subject is the same
 * canonical entity a school has adopted (spec §8).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_subjects')]
#[ORM\HasLifecycleCallbacks]
class CatalogSubject
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 30, unique: true)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** e.g. "NERDC", "WAEC", "Cambridge". */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $curriculum = 'NERDC';

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    public function __construct(string $name, string $code)
    {
        $this->name = $name;
        $this->code = strtoupper($code);
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function getCode(): string { return $this->code; }
    public function setDescription(?string $v): void { $this->description = $v; }
    public function setCurriculum(?string $v): void { $this->curriculum = $v; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): void { $this->isActive = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'curriculum' => $this->curriculum,
            'is_active' => $this->isActive,
        ];
    }
}
