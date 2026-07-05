<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Ordered link between an assessment and a question, with an optional marks override. */
#[ORM\Entity]
#[ORM\Table(name: 'assessment_questions')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_question', columns: ['assessment_id', 'question_id'])]
class AssessmentQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assessment::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Assessment $assessment;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    /** Overrides the question's own marks within this assessment when set. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $marks = null;

    public function __construct(Assessment $assessment, Question $question, int $position = 0)
    {
        $this->assessment = $assessment;
        $this->question = $question;
        $this->position = $position;
    }

    public function getId(): ?int { return $this->id; }
    public function getAssessment(): Assessment { return $this->assessment; }
    public function getQuestion(): Question { return $this->question; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): void { $this->position = $v; }
    public function setMarks(?int $v): void { $this->marks = $v !== null ? max(1, $v) : null; }

    public function effectiveMarks(): int
    {
        return $this->marks ?? $this->question->getMarks();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question->getId(),
            'position' => $this->position,
            'marks' => $this->effectiveMarks(),
            'type' => $this->question->getType(),
            'stem' => $this->question->getStem(),
            'difficulty' => $this->question->getDifficulty(),
            'answer_validated' => $this->question->isAnswerValidated(),
            'approval_status' => $this->question->getApprovalStatus(),
        ];
    }
}
