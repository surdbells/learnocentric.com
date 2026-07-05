<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quiz / test / worksheet assembled from validated questions. It cannot be
 * published unless every attached question has passed the answer gate (§14).
 */
#[ORM\Entity]
#[ORM\Table(name: 'assessments')]
#[ORM\HasLifecycleCallbacks]
class Assessment implements LifecycleAware
{
    use TimestampsTrait;

    public const TYPES = ['quiz', 'test', 'exam', 'worksheet'];
    public const TRACKS = ['academic', 'competency'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $term = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 20)]
    private string $type = 'quiz';

    #[ORM\Column(length: 20)]
    private string $track = 'academic';

    #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT, nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(name: 'pass_mark', type: Types::SMALLINT, options: ['default' => 50])]
    private int $passMark = 50;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(name: 'approval_status', length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $approvalStatus = Lifecycle::DRAFT;

    /** @var Collection<int, AssessmentQuestion> */
    #[ORM\OneToMany(mappedBy: 'assessment', targetEntity: AssessmentQuestion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct(Subject $subject, string $title)
    {
        $this->subject = $subject;
        $this->title = $title;
        $this->items = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): Subject { return $this->subject; }
    public function getTopic(): ?Topic { return $this->topic; }
    public function setTopic(?Topic $v): void { $this->topic = $v; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function setTerm(?Term $v): void { $this->term = $v; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getType(): string { return $this->type; }
    public function setType(string $v): void { $this->type = in_array($v, self::TYPES, true) ? $v : 'quiz'; }
    public function getTrack(): string { return $this->track; }
    public function setTrack(string $v): void { $this->track = in_array($v, self::TRACKS, true) ? $v : 'academic'; }
    public function setDurationMinutes(?int $v): void { $this->durationMinutes = $v; }
    public function getPassMark(): int { return $this->passMark; }
    public function setPassMark(int $v): void { $this->passMark = max(0, min(100, $v)); }
    public function setInstructions(?string $v): void { $this->instructions = $v; }
    public function getApprovalStatus(): string { return $this->approvalStatus; }
    public function setApprovalStatus(string $v): void { $this->approvalStatus = $v; }

    /** @return Collection<int, AssessmentQuestion> */
    public function getItems(): Collection { return $this->items; }

    public function totalMarks(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->effectiveMarks();
        }
        return $total;
    }

    /** Attached questions still failing the answer gate — empty means publishable. */
    public function unvalidatedQuestions(): array
    {
        $bad = [];
        foreach ($this->items as $item) {
            if (!$item->getQuestion()->isAnswerValidated()) {
                $bad[] = $item->getQuestion()->getId();
            }
        }
        return $bad;
    }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->approvalStatus; }
    public function setLifecycleStatus(string $status): void { $this->approvalStatus = $status; }
    public function lifecycleType(): string { return 'Assessment'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(bool $withItems = false): array
    {
        $data = [
            'id' => $this->id,
            'subject_id' => $this->subject->getId(),
            'subject' => $this->subject->getName(),
            'topic_id' => $this->topic?->getId(),
            'topic' => $this->topic?->getTitle(),
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'term_id' => $this->term?->getId(),
            'title' => $this->title,
            'type' => $this->type,
            'track' => $this->track,
            'duration_minutes' => $this->durationMinutes,
            'pass_mark' => $this->passMark,
            'instructions' => $this->instructions,
            'question_count' => $this->items->count(),
            'total_marks' => $this->totalMarks(),
            'approval_status' => $this->approvalStatus,
        ];
        if ($withItems) {
            $data['questions'] = array_map(static fn (AssessmentQuestion $i) => $i->toArray(), $this->items->toArray());
        }
        return $data;
    }
}
