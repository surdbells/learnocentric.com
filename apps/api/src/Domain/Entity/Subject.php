<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A subject taught at a class level (e.g. Mathematics for JSS 1). */
#[ORM\Entity]
#[ORM\Table(name: 'subjects')]
#[ORM\HasLifecycleCallbacks]
class Subject
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $code = null;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    /** e.g. "NERDC", "WAEC", "custom" */
    #[ORM\Column(name: 'curriculum_source', length: 60, nullable: true)]
    private ?string $curriculumSource = 'NERDC';

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    public function __construct(Institution $institution, string $name)
    {
        $this->institution = $institution;
        $this->name = $name;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstitution(): Institution { return $this->institution; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function setCode(?string $v): void { $this->code = $v; }
    public function getSchoolClass(): ?SchoolClass { return $this->schoolClass; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function setCurriculumSource(?string $v): void { $this->curriculumSource = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'name' => $this->name,
            'code' => $this->code,
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'curriculum_source' => $this->curriculumSource,
            'status' => $this->status,
        ];
    }
}
