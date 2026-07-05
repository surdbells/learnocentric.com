<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A term within an academic session (First / Second / Third). */
#[ORM\Entity]
#[ORM\Table(name: 'terms')]
#[ORM\HasLifecycleCallbacks]
class Term
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AcademicSession::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicSession $session;

    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(name: 'sequence', type: Types::SMALLINT, options: ['default' => 1])]
    private int $sequence = 1;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $endDate = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(name: 'is_current', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCurrent = false;

    public function __construct(AcademicSession $session, string $name, int $sequence = 1)
    {
        $this->session = $session;
        $this->name = $name;
        $this->sequence = $sequence;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getSession(): AcademicSession { return $this->session; }
    public function getName(): string { return $this->name; }
    public function setStartDate(?DateTimeInterface $v): void { $this->startDate = $v; }
    public function setEndDate(?DateTimeInterface $v): void { $this->endDate = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function setCurrent(bool $v): void { $this->isCurrent = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session->getId(),
            'name' => $this->name,
            'sequence' => $this->sequence,
            'start_date' => $this->startDate?->format('Y-m-d'),
            'end_date' => $this->endDate?->format('Y-m-d'),
            'status' => $this->status,
            'is_current' => $this->isCurrent,
        ];
    }
}
