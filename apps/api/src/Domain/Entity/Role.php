<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A platform or school-scoped role (e.g. super_admin, school_admin, teacher, student).
 * Permissions are attached via RolePermission for table-driven RBAC.
 */
#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    public const SUPER_ADMIN = 'super_admin';
    public const SCHOOL_ADMIN = 'school_admin';
    public const TUTOR_ADMIN = 'tutor_admin';
    public const ACADEMIC_LEAD = 'academic_lead';
    public const TEACHER = 'teacher';
    public const STUDENT = 'student';
    public const PARENT = 'parent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    /** platform | school */
    #[ORM\Column(length: 20, options: ['default' => 'school'])]
    private string $scope = 'school';

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystem = false;

    /** Set for institution-scoped custom roles; null for global system roles. */
    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institution $institution = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name, string $scope = 'school', bool $isSystem = true)
    {
        $this->code = $code;
        $this->name = $name;
        $this->scope = $scope;
        $this->isSystem = $isSystem;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function getScope(): string { return $this->scope; }
    public function setScope(string $v): void { $this->scope = $v; }
    public function isSystem(): bool { return $this->isSystem; }
    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $v): void { $this->institution = $v; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): void { $this->description = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'scope' => $this->scope,
            'is_system' => $this->isSystem,
            'institution_id' => $this->institution?->getId(),
            'description' => $this->description,
        ];
    }
}
