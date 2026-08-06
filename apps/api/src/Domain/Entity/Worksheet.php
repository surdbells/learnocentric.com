<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A topic-linked worksheet students complete and submit (spec §14 learner
 * journey). Uses the shared content lifecycle; scores feed the gradebook.
 */
#[ORM\Entity]
#[ORM\Table(name: 'worksheets')]
#[ORM\HasLifecycleCallbacks]
class Worksheet implements LifecycleAware
{
    use TimestampsTrait;

    public const TRACKS = ['academic', 'competency'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 20)]
    private string $track = 'academic';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    /** Optional attachment (worksheet PDF/doc) stored via Flysystem. */
    #[ORM\Column(name: 'attachment_url', length: 500, nullable: true)]
    private ?string $attachmentUrl = null;

    #[ORM\Column(name: 'total_marks', type: Types::SMALLINT, options: ['default' => 10])]
    private int $totalMarks = 10;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(name: 'approval_status', length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $approvalStatus = Lifecycle::DRAFT;

    public function __construct(Topic $topic, string $title)
    {
        $this->topic = $topic;
        $this->title = $title;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): Topic { return $this->topic; }
    public function getSchoolClass(): ?SchoolClass { return $this->schoolClass; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getTrack(): string { return $this->track; }
    public function setTrack(string $v): void { $this->track = in_array($v, self::TRACKS, true) ? $v : 'academic'; }
    public function setInstructions(?string $v): void { $this->instructions = $v; }
    public function setAttachmentUrl(?string $v): void { $this->attachmentUrl = \App\Service\Storage\FilePath::toPath($v); }
    public function getTotalMarks(): int { return $this->totalMarks; }
    public function setTotalMarks(int $v): void { $this->totalMarks = max(1, $v); }
    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $v): void { $this->dueDate = $v; }
    public function getApprovalStatus(): string { return $this->approvalStatus; }
    public function setApprovalStatus(string $v): void { $this->approvalStatus = $v; }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->approvalStatus; }
    public function setLifecycleStatus(string $status): void { $this->approvalStatus = $status; }
    public function lifecycleType(): string { return 'Worksheet'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic->getId(),
            'topic' => $this->topic->getTitle(),
            'subject_id' => $this->topic->getSubject()->getId(),
            'subject' => $this->topic->getSubject()->getName(),
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'title' => $this->title,
            'track' => $this->track,
            'instructions' => $this->instructions,
            'attachment_url' => \App\Service\Storage\FilePath::toUrl($this->attachmentUrl),
            'total_marks' => $this->totalMarks,
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'approval_status' => $this->approvalStatus,
        ];
    }
}
