<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The official weekly teaching sequence: which topic is taught in which week. */
#[ORM\Entity]
#[ORM\Table(name: 'scheme_of_work')]
#[ORM\HasLifecycleCallbacks]
class SchemeOfWork implements LifecycleAware
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $term = null;

    #[ORM\Column(name: 'week_number', type: Types::SMALLINT)]
    private int $weekNumber;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objective = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_teacher_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTeacher = null;

    #[ORM\Column(length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $status = Lifecycle::DRAFT;

    public function __construct(SchoolClass $schoolClass, Subject $subject, int $weekNumber)
    {
        $this->schoolClass = $schoolClass;
        $this->subject = $subject;
        $this->weekNumber = $weekNumber;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getWeekNumber(): int { return $this->weekNumber; }
    public function setWeekNumber(int $v): void { $this->weekNumber = $v; }
    public function setTerm(?Term $v): void { $this->term = $v; }
    public function setTopic(?Topic $v): void { $this->topic = $v; }
    public function setObjective(?string $v): void { $this->objective = $v; }
    public function setAssignedTeacher(?User $v): void { $this->assignedTeacher = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->status; }
    public function setLifecycleStatus(string $status): void { $this->status = $status; }
    public function lifecycleType(): string { return 'SchemeOfWork'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->schoolClass->getId(),
            'class_label' => $this->schoolClass->getLabel(),
            'subject_id' => $this->subject->getId(),
            'subject' => $this->subject->getName(),
            'term_id' => $this->term?->getId(),
            'week_number' => $this->weekNumber,
            'topic_id' => $this->topic?->getId(),
            'topic' => $this->topic?->getTitle(),
            'objective' => $this->objective,
            'assigned_teacher_id' => $this->assignedTeacher?->getId(),
            'status' => $this->status,
        ];
    }
}
