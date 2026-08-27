<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A question in the bank, tied to a topic. Every question must carry a
 * well-formed, validated correct answer before it can be published or added
 * to an assessment, the answer-validation gate the spec insists on (§14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'questions')]
#[ORM\HasLifecycleCallbacks]
class Question implements LifecycleAware
{
    use TimestampsTrait;

    public const TYPES = ['mcq', 'multi', 'true_false', 'short', 'numeric'];
    public const TRACKS = ['academic', 'competency'];
    public const DIFFICULTIES = ['foundational', 'moderate', 'challenging', 'extension'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\Column(length: 20)]
    private string $type = 'mcq';

    #[ORM\Column(length: 20)]
    private string $track = 'academic';

    #[ORM\Column(length: 20)]
    private string $difficulty = 'moderate';

    /** Optional tag naming the common misconception this question probes (spec §14). */
    #[ORM\Column(name: 'misconception_tag', length: 120, nullable: true)]
    private ?string $misconceptionTag = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $stem;

    /** For MCQ: list of { key, text }. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    /** MCQ: option key. true_false: 'true'|'false'. short: [accepted…]. numeric: { value, tolerance }. */
    #[ORM\Column(name: 'correct_answer', type: Types::JSON, nullable: true)]
    private mixed $correctAnswer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $marks = 1;

    #[ORM\Column(name: 'answer_validated', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $answerValidated = false;

    #[ORM\Column(name: 'approval_status', length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $approvalStatus = Lifecycle::DRAFT;

    public function __construct(Topic $topic, string $stem)
    {
        $this->topic = $topic;
        $this->stem = $stem;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): Topic { return $this->topic; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = in_array($v, self::TYPES, true) ? $v : 'mcq'; }
    public function getTrack(): string { return $this->track; }
    public function setTrack(string $v): void { $this->track = in_array($v, self::TRACKS, true) ? $v : 'academic'; }
    public function getDifficulty(): string { return $this->difficulty; }
    public function setDifficulty(string $v): void { $this->difficulty = in_array($v, self::DIFFICULTIES, true) ? $v : 'moderate'; }
    public function getMisconceptionTag(): ?string { return $this->misconceptionTag; }
    public function setMisconceptionTag(?string $v): void { $this->misconceptionTag = $v !== null && trim($v) !== '' ? trim($v) : null; }
    public function getStem(): string { return $this->stem; }
    public function setStem(string $v): void { $this->stem = $v; }
    public function getOptions(): ?array { return $this->options; }
    public function setOptions(?array $v): void { $this->options = $v; }
    public function getCorrectAnswer(): mixed { return $this->correctAnswer; }
    public function setCorrectAnswer(mixed $v): void { $this->correctAnswer = $v; }
    public function getExplanation(): ?string { return $this->explanation; }
    public function setExplanation(?string $v): void { $this->explanation = $v; }
    public function getMarks(): int { return $this->marks; }
    public function setMarks(int $v): void { $this->marks = max(1, $v); }
    public function isAnswerValidated(): bool { return $this->answerValidated; }
    public function setAnswerValidated(bool $v): void { $this->answerValidated = $v; }
    public function getApprovalStatus(): string { return $this->approvalStatus; }
    public function setApprovalStatus(string $v): void { $this->approvalStatus = $v; }

    /**
     * The answer-validation gate. Returns a human error when the correct answer
     * is missing or malformed for the question type, or null when it is sound.
     */
    public function answerError(): ?string
    {
        $ans = $this->correctAnswer;
        switch ($this->type) {
            case 'mcq':
                $keys = array_column($this->options ?? [], 'key');
                if (count($keys) < 2) {
                    return 'A multiple-choice question needs at least two options.';
                }
                if (count($keys) !== count(array_unique($keys))) {
                    return 'Option keys must be unique.';
                }
                if (!is_string($ans) || !in_array($ans, $keys, true)) {
                    return 'The correct answer must match one of the option keys.';
                }
                return null;
            case 'multi':
                $keys = array_column($this->options ?? [], 'key');
                if (count($keys) < 2) {
                    return 'A multi-select question needs at least two options.';
                }
                if (count($keys) !== count(array_unique($keys))) {
                    return 'Option keys must be unique.';
                }
                if (!is_array($ans) || $ans === []) {
                    return 'Mark at least one option as correct.';
                }
                foreach ($ans as $key) {
                    if (!is_string($key) || !in_array($key, $keys, true)) {
                        return 'Every correct answer must match one of the option keys.';
                    }
                }
                if (count($ans) !== count(array_unique($ans))) {
                    return 'The correct answers must not repeat an option key.';
                }
                return null;
            case 'true_false':
                if (!in_array($ans, ['true', 'false', true, false], true)) {
                    return 'The correct answer must be true or false.';
                }
                return null;
            case 'short':
                $accepted = is_array($ans) ? $ans : ($ans === null || $ans === '' ? [] : [$ans]);
                $accepted = array_filter($accepted, static fn ($a) => trim((string) $a) !== '');
                if (empty($accepted)) {
                    return 'Provide at least one acceptable answer.';
                }
                return null;
            case 'numeric':
                $val = is_array($ans) ? ($ans['value'] ?? null) : $ans;
                if (!is_numeric($val)) {
                    return 'The correct answer must be a number.';
                }
                return null;
            default:
                return 'Unknown question type.';
        }
    }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->approvalStatus; }
    public function setLifecycleStatus(string $status): void { $this->approvalStatus = $status; }
    public function lifecycleType(): string { return 'Question'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic->getId(),
            'topic' => $this->topic->getTitle(),
            'subject_id' => $this->topic->getSubject()->getId(),
            'subject' => $this->topic->getSubject()->getName(),
            'type' => $this->type,
            'track' => $this->track,
            'difficulty' => $this->difficulty,
            'misconception_tag' => $this->misconceptionTag,
            'stem' => $this->stem,
            'options' => $this->options,
            'correct_answer' => $this->correctAnswer,
            'explanation' => $this->explanation,
            'marks' => $this->marks,
            'answer_validated' => $this->answerValidated,
            'approval_status' => $this->approvalStatus,
        ];
    }
}
