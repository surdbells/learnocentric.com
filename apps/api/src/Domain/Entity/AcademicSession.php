<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An academic year for an institution (e.g. "2025/2026"). */
#[ORM\Entity]
#[ORM\Table(name: 'academic_sessions')]
#[ORM\HasLifecycleCallbacks]
class AcademicSession
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $endDate = null;

    /** active | closed | upcoming */
    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(name: 'is_current', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCurrent = false;

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
    public function setStartDate(?DateTimeInterface $v): void { $this->startDate = $v; }
    public function setEndDate(?DateTimeInterface $v): void { $this->endDate = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function setCurrent(bool $v): void { $this->isCurrent = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution->getId(),
            'name' => $this->name,
            'start_date' => $this->startDate?->format('Y-m-d'),
            'end_date' => $this->endDate?->format('Y-m-d'),
            'status' => $this->status,
            'is_current' => $this->isCurrent,
        ];
    }
}
