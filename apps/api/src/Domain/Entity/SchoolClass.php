<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A class level + arm/section within an institution (e.g. "JSS 1" arm "A").
 * Supports Nigerian labels (JSS/SS/Basic) via free-text level + arm.
 */
#[ORM\Entity]
#[ORM\Table(name: 'school_classes')]
#[ORM\HasLifecycleCallbacks]
class SchoolClass
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    /** e.g. "JSS 1", "SS 2", "Basic 3" */
    #[ORM\Column(length: 50)]
    private string $level;

    /** e.g. "A", "B", "Gold" (nullable when the class has a single arm) */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $arm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'class_teacher_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $classTeacher = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    public function __construct(Institution $institution, string $level, ?string $arm = null)
    {
        $this->institution = $institution;
        $this->level = $level;
        $this->arm = $arm;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstitution(): Institution { return $this->institution; }
    public function getLevel(): string { return $this->level; }
    public function setLevel(string $v): void { $this->level = $v; }
    public function getArm(): ?string { return $this->arm; }
    public function setArm(?string $v): void { $this->arm = $v; }
    public function getClassTeacher(): ?User { return $this->classTeacher; }
    public function setClassTeacher(?User $v): void { $this->classTeacher = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }

    public function getLabel(): string
    {
        return trim($this->level . ' ' . ($this->arm ?? ''));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'level' => $this->level,
            'arm' => $this->arm,
            'label' => $this->getLabel(),
            'class_teacher_id' => $this->classTeacher?->getId(),
            'class_teacher' => $this->classTeacher ? trim($this->classTeacher->getFirstName() . ' ' . $this->classTeacher->getLastName()) : null,
            'status' => $this->status,
        ];
    }
}
