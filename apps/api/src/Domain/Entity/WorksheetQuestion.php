<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One question in a structured worksheet. Grouped into sections by a label +
 * position (the design's "Section A / B / C"). Objective types carry a correct
 * answer and are auto-scored on submit; free-response is marked by the teacher.
 */
#[ORM\Entity]
#[ORM\Table(name: 'worksheet_questions')]
class WorksheetQuestion
{
    public const TYPES = ['numeric', 'mcq', 'short', 'true_false', 'free_response'];
    public const AUTO_TYPES = ['numeric', 'mcq', 'short', 'true_false'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Worksheet::class)]
    #[ORM\JoinColumn(name: 'worksheet_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Worksheet $worksheet;

    #[ORM\Column(name: 'section_label', length: 160, nullable: true)]
    private ?string $sectionLabel = null;

    #[ORM\Column(name: 'section_position', type: Types::SMALLINT, options: ['default' => 0])]
    private int $sectionPosition = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $prompt;

    #[ORM\Column(length: 20, options: ['default' => 'numeric'])]
    private string $type = 'numeric';

    /** For mcq: a list of option strings. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    /** Expected answer for objective types (pipe-separated acceptable answers allowed). */
    #[ORM\Column(name: 'correct_answer', type: Types::TEXT, nullable: true)]
    private ?string $correctAnswer = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $marks = 1;

    public function __construct(Worksheet $worksheet, string $prompt)
    {
        $this->worksheet = $worksheet;
        $this->prompt = $prompt;
    }

    public function getId(): ?int { return $this->id; }
    public function getWorksheet(): Worksheet { return $this->worksheet; }
    public function getSectionLabel(): ?string { return $this->sectionLabel; }
    public function setSectionLabel(?string $v): void { $this->sectionLabel = $v; }
    public function getSectionPosition(): int { return $this->sectionPosition; }
    public function setSectionPosition(int $v): void { $this->sectionPosition = $v; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): void { $this->position = $v; }
    public function getPrompt(): string { return $this->prompt; }
    public function setPrompt(string $v): void { $this->prompt = $v; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = in_array($v, self::TYPES, true) ? $v : 'numeric'; }
    public function getOptions(): ?array { return $this->options; }
    public function setOptions(?array $v): void { $this->options = $v; }
    public function getCorrectAnswer(): ?string { return $this->correctAnswer; }
    public function setCorrectAnswer(?string $v): void { $this->correctAnswer = $v; }
    public function getMarks(): int { return $this->marks; }
    public function setMarks(int $v): void { $this->marks = max(1, $v); }

    public function isAutoGradable(): bool
    {
        return in_array($this->type, self::AUTO_TYPES, true) && $this->correctAnswer !== null && trim($this->correctAnswer) !== '';
    }

    /** Learner-facing shape — never leaks the correct answer. */
    public function toLearnerArray(): array
    {
        return [
            'id' => $this->id,
            'section_label' => $this->sectionLabel,
            'section_position' => $this->sectionPosition,
            'position' => $this->position,
            'prompt' => $this->prompt,
            'type' => $this->type,
            'options' => $this->options,
            'marks' => $this->marks,
        ];
    }

    /** Staff-facing shape — includes the correct answer. */
    public function toArray(): array
    {
        return $this->toLearnerArray() + ['correct_answer' => $this->correctAnswer];
    }
}
