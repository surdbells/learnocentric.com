<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A learner's question to a tutor (Ask Tutor). Optionally directed at a specific
 * tutor and/or subject; a tutor answers it, moving it open → answered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tutor_questions')]
#[ORM\HasLifecycleCallbacks]
class TutorQuestion
{
    use TimestampsTrait;

    public const OPEN = 'open';
    public const ANSWERED = 'answered';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tutor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $tutor = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subject $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $question;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    #[ORM\Column(length: 12, options: ['default' => 'open'])]
    private string $status = self::OPEN;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'answered_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $answeredBy = null;

    #[ORM\Column(name: 'answered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct(User $student, string $question)
    {
        $this->student = $student;
        $this->question = $question;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getStudent(): User { return $this->student; }
    public function getTutor(): ?User { return $this->tutor; }
    public function setTutor(?User $v): void { $this->tutor = $v; }
    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(?Subject $v): void { $this->subject = $v; }
    public function getQuestion(): string { return $this->question; }
    public function getAnswer(): ?string { return $this->answer; }
    public function getStatus(): string { return $this->status; }

    public function answer(string $answer, User $by): void
    {
        $this->answer = $answer;
        $this->answeredBy = $by;
        $this->status = self::ANSWERED;
        $this->answeredAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student' => trim($this->student->getFirstName() . ' ' . $this->student->getLastName()),
            'student_id' => $this->student->getId(),
            'tutor' => $this->tutor ? trim($this->tutor->getFirstName() . ' ' . $this->tutor->getLastName()) : null,
            'tutor_id' => $this->tutor?->getId(),
            'subject' => $this->subject?->getName(),
            'subject_id' => $this->subject?->getId(),
            'question' => $this->question,
            'answer' => $this->answer,
            'status' => $this->status,
            'answered_by' => $this->answeredBy ? trim($this->answeredBy->getFirstName() . ' ' . $this->answeredBy->getLastName()) : null,
            'answered_at' => $this->answeredAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
