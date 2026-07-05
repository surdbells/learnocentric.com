<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Links a parent/guardian account to a student they may view reports for. */
#[ORM\Entity]
#[ORM\Table(name: 'guardian_links')]
#[ORM\UniqueConstraint(name: 'uniq_guardian_student', columns: ['guardian_id', 'student_id'])]
class GuardianLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'guardian_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $guardian;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(length: 40, options: ['default' => 'parent'])]
    private string $relationship = 'parent';

    public function __construct(User $guardian, User $student, string $relationship = 'parent')
    {
        $this->guardian = $guardian;
        $this->student = $student;
        $this->relationship = $relationship;
    }

    public function getId(): ?int { return $this->id; }
    public function getGuardian(): User { return $this->guardian; }
    public function getStudent(): User { return $this->student; }
    public function getRelationship(): string { return $this->relationship; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'guardian_id' => $this->guardian->getId(),
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'relationship' => $this->relationship,
        ];
    }
}
