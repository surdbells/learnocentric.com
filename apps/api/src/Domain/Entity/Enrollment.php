<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Enrolls a student into a class (optionally for a session/term). */
#[ORM\Entity]
#[ORM\Table(name: 'enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_student_class', columns: ['student_id', 'class_id'])]
#[ORM\HasLifecycleCallbacks]
class Enrollment
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    #[ORM\ManyToOne(targetEntity: AcademicSession::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicSession $session = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $term = null;

    #[ORM\Column(name: 'enrollment_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $enrollmentDate = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    public function __construct(User $student, SchoolClass $schoolClass)
    {
        $this->student = $student;
        $this->schoolClass = $schoolClass;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): User { return $this->student; }
    public function getSchoolClass(): SchoolClass { return $this->schoolClass; }
    public function setSchoolClass(SchoolClass $v): void { $this->schoolClass = $v; }
    public function setSession(?AcademicSession $v): void { $this->session = $v; }
    public function setTerm(?Term $v): void { $this->term = $v; }
    public function setEnrollmentDate(?DateTimeInterface $v): void { $this->enrollmentDate = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }

    public function toArray(): array
    {
        $s = $this->student;

        return [
            'id' => $this->id,
            'student_id' => $s->getId(),
            'email' => $s->getEmail(),
            'first_name' => $s->getFirstName(),
            'last_name' => $s->getLastName(),
            'phone' => $s->getPhone(),
            'profile_image_url' => \App\Service\Storage\FilePath::toUrl($s->getProfileImageUrl()),
            'is_active' => $s->getStatus() === 'active',
            'class_id' => $this->schoolClass->getId(),
            'class_label' => $this->schoolClass->getLabel(),
            'session_id' => $this->session?->getId(),
            'term_id' => $this->term?->getId(),
            'enrollment_date' => $this->enrollmentDate?->format('Y-m-d'),
            'status' => $this->status,
        ];
    }
}
