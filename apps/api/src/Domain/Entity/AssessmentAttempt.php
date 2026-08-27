<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's attempt at a published assessment. Its score feeds the academic
 * or competency track (kept separate per spec §14) depending on the assessment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assessment_attempts')]
#[ORM\HasLifecycleCallbacks]
class AssessmentAttempt
{
    use TimestampsTrait;

    public const IN_PROGRESS = 'in_progress';
    public const SUBMITTED = 'submitted';
    public const GRADED = 'graded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assessment::class)]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Assessment $assessment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(length: 20)]
    private string $status = self::IN_PROGRESS;

    /** academic | competency, snapshot from the assessment. */
    #[ORM\Column(length: 20)]
    private string $track = 'academic';

    #[ORM\Column(name: 'total_marks', type: Types::SMALLINT, options: ['default' => 0])]
    private int $totalMarks = 0;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $percentage = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $passed = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** @var Collection<int, AttemptAnswer> */
    #[ORM\OneToMany(mappedBy: 'attempt', targetEntity: AttemptAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    public function __construct(Assessment $assessment, User $student)
    {
        $this->assessment = $assessment;
        $this->student = $student;
        $this->answers = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getAssessment(): Assessment { return $this->assessment; }
    public function getStudent(): User { return $this->student; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getTrack(): string { return $this->track; }
    public function setTrack(string $v): void { $this->track = $v; }
    public function getTotalMarks(): int { return $this->totalMarks; }
    public function setTotalMarks(int $v): void { $this->totalMarks = $v; }
    public function getScore(): ?int { return $this->score; }
    public function setScore(?int $v): void { $this->score = $v; }
    public function getPercentage(): ?float { return $this->percentage; }
    public function setPercentage(?float $v): void { $this->percentage = $v; }
    public function isPassed(): ?bool { return $this->passed; }
    public function setPassed(?bool $v): void { $this->passed = $v; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $v): void { $this->startedAt = $v; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $v): void { $this->submittedAt = $v; }

    /** @return Collection<int, AttemptAnswer> */
    public function getAnswers(): Collection { return $this->answers; }

    public function findAnswer(int $questionId): ?AttemptAnswer
    {
        foreach ($this->answers as $a) {
            if ($a->getQuestion()->getId() === $questionId) {
                return $a;
            }
        }
        return null;
    }

    public function toArray(bool $withAnswers = false): array
    {
        $data = [
            'id' => $this->id,
            'assessment_id' => $this->assessment->getId(),
            'assessment' => $this->assessment->getTitle(),
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'status' => $this->status,
            'track' => $this->track,
            'total_marks' => $this->totalMarks,
            'score' => $this->score,
            'percentage' => $this->percentage,
            'passed' => $this->passed,
            'started_at' => $this->startedAt?->format(DATE_ATOM),
            'submitted_at' => $this->submittedAt?->format(DATE_ATOM),
        ];
        if ($withAnswers) {
            $data['answers'] = array_map(static fn (AttemptAnswer $a) => $a->toArray(), $this->answers->toArray());
        }
        return $data;
    }
}
