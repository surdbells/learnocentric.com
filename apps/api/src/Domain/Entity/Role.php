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
    public function getScope(): string { return $this->scope; }
    public function isSystem(): bool { return $this->isSystem; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'scope' => $this->scope,
        ];
    }
}
