<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Records that a student joined a live class. */
#[ORM\Entity]
#[ORM\Table(name: 'live_class_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_liveclass_student', columns: ['live_class_id', 'student_id'])]
class LiveClassAttendance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LiveClass::class)]
    #[ORM\JoinColumn(name: 'live_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LiveClass $liveClass;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    public function __construct(LiveClass $liveClass, User $student, \DateTimeImmutable $joinedAt)
    {
        $this->liveClass = $liveClass;
        $this->student = $student;
        $this->joinedAt = $joinedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getLiveClass(): LiveClass { return $this->liveClass; }
    public function getStudent(): User { return $this->student; }
    public function getJoinedAt(): \DateTimeImmutable { return $this->joinedAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'joined_at' => $this->joinedAt->format(DATE_ATOM),
        ];
    }
}
