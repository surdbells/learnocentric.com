<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A student's answer to one worksheet question, with the mark awarded. */
#[ORM\Entity]
#[ORM\Table(name: 'worksheet_responses')]
#[ORM\UniqueConstraint(name: 'uniq_submission_question', columns: ['submission_id', 'question_id'])]
class WorksheetResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorksheetSubmission::class)]
    #[ORM\JoinColumn(name: 'submission_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WorksheetSubmission $submission;

    #[ORM\ManyToOne(targetEntity: WorksheetQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WorksheetQuestion $question;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    #[ORM\Column(name: 'awarded_marks', type: Types::SMALLINT, nullable: true)]
    private ?int $awardedMarks = null;

    #[ORM\Column(nullable: true)]
    private ?bool $correct = null;

    public function __construct(WorksheetSubmission $submission, WorksheetQuestion $question)
    {
        $this->submission = $submission;
        $this->question = $question;
    }

    public function getId(): ?int { return $this->id; }
    public function getSubmission(): WorksheetSubmission { return $this->submission; }
    public function getQuestion(): WorksheetQuestion { return $this->question; }
    public function getAnswer(): ?string { return $this->answer; }
    public function setAnswer(?string $v): void { $this->answer = $v; }
    public function getAwardedMarks(): ?int { return $this->awardedMarks; }
    public function setAwardedMarks(?int $v): void { $this->awardedMarks = $v; }
    public function getCorrect(): ?bool { return $this->correct; }
    public function setCorrect(?bool $v): void { $this->correct = $v; }

    public function toArray(): array
    {
        return [
            'question_id' => $this->question->getId(),
            'answer' => $this->answer,
            'awarded_marks' => $this->awardedMarks,
            'correct' => $this->correct,
        ];
    }
}
