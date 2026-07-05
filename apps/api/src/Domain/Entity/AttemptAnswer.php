<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A student's answer to one question within an attempt, with the graded outcome. */
#[ORM\Entity]
#[ORM\Table(name: 'attempt_answers')]
#[ORM\UniqueConstraint(name: 'uniq_attempt_question', columns: ['attempt_id', 'question_id'])]
class AttemptAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssessmentAttempt::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssessmentAttempt $attempt;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    /** The student's raw answer, shape mirrors the question type. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $response = null;

    #[ORM\Column(name: 'is_correct', type: Types::BOOLEAN, nullable: true)]
    private ?bool $isCorrect = null;

    #[ORM\Column(name: 'marks_awarded', type: Types::SMALLINT, options: ['default' => 0])]
    private int $marksAwarded = 0;

    public function __construct(AssessmentAttempt $attempt, Question $question)
    {
        $this->attempt = $attempt;
        $this->question = $question;
    }

    public function getId(): ?int { return $this->id; }
    public function getQuestion(): Question { return $this->question; }
    public function getResponse(): mixed { return $this->response; }
    public function setResponse(mixed $v): void { $this->response = $v; }
    public function isCorrect(): ?bool { return $this->isCorrect; }
    public function setCorrect(?bool $v): void { $this->isCorrect = $v; }
    public function getMarksAwarded(): int { return $this->marksAwarded; }
    public function setMarksAwarded(int $v): void { $this->marksAwarded = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question->getId(),
            'stem' => $this->question->getStem(),
            'type' => $this->question->getType(),
            'response' => $this->response,
            'is_correct' => $this->isCorrect,
            'marks_awarded' => $this->marksAwarded,
            'marks' => $this->question->getMarks(),
        ];
    }
}
