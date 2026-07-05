<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Assigns a teacher to teach a subject to a class (optionally for a term). */
#[ORM\Entity]
#[ORM\Table(name: 'teacher_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_class_subject_term', columns: ['teacher_id', 'class_id', 'subject_id', 'term_id'])]
#[ORM\HasLifecycleCallbacks]
class TeacherAssignment
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $term = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    public function __construct(User $teacher, SchoolClass $schoolClass, Subject $subject, ?Term $term = null)
    {
        $this->teacher = $teacher;
        $this->schoolClass = $schoolClass;
        $this->subject = $subject;
        $this->term = $term;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTeacher(): User { return $this->teacher; }
    public function getSchoolClass(): SchoolClass { return $this->schoolClass; }
    public function getSubject(): Subject { return $this->subject; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher->getId(),
            'teacher' => trim($this->teacher->getFirstName() . ' ' . $this->teacher->getLastName()),
            'class_id' => $this->schoolClass->getId(),
            'class_label' => $this->schoolClass->getLabel(),
            'subject_id' => $this->subject->getId(),
            'subject' => $this->subject->getName(),
            'term_id' => $this->term?->getId(),
            'status' => $this->status,
        ];
    }
}
