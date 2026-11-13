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

    /**
     * Custom roles are built on a system role (e.g. teacher, school_admin): they
     * get that role's access, narrowed by their own permission matrix. Null for
     * system roles.
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'base_role_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Role $baseRole = null;

    /**
     * The system-role code this role acts as for authorization: its own code for
     * system roles, the base role's code for custom roles. Denormalized so DQL can
     * filter users by it (`r.effectiveCode`).
     */
    #[ORM\Column(name: 'effective_code', length: 50)]
    private string $effectiveCode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name, string $scope = 'school', bool $isSystem = true)
    {
        $this->code = $code;
        $this->effectiveCode = $code;
        $this->name = $name;
        $this->scope = $scope;
        $this->isSystem = $isSystem;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    /** The system-role code to authorize as (see $effectiveCode). */
    public function effectiveCode(): string { return $this->effectiveCode !== '' ? $this->effectiveCode : $this->code; }
    public function getBaseRole(): ?Role { return $this->baseRole; }
    public function setBaseRole(?Role $base): void
    {
        $this->baseRole = $base;
        $this->effectiveCode = $base?->getCode() ?? $this->code;
    }
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
            'base_role' => $this->baseRole?->getCode(),
            'base_role_id' => $this->baseRole?->getId(),
            'base_role_name' => $this->baseRole?->getName(),
            'effective_code' => $this->effectiveCode(),
        ];
    }
}
