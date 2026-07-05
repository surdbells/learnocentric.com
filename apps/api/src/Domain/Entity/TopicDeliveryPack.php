<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The teachable/trackable bundle for a topic (teacher guide, learner note,
 * video, parent wording). Worksheet/quiz/assessment/portfolio links are added
 * in later phases; this holds the narrative components + lifecycle + version.
 */
#[ORM\Entity]
#[ORM\Table(name: 'topic_delivery_packs')]
#[ORM\HasLifecycleCallbacks]
class TopicDeliveryPack
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\Column(name: 'teacher_guide', type: Types::TEXT, nullable: true)]
    private ?string $teacherGuide = null;

    #[ORM\Column(name: 'learner_note', type: Types::TEXT, nullable: true)]
    private ?string $learnerNote = null;

    #[ORM\Column(name: 'video_url', length: 1024, nullable: true)]
    private ?string $videoUrl = null;

    #[ORM\Column(name: 'worked_examples', type: Types::TEXT, nullable: true)]
    private ?string $workedExamples = null;

    #[ORM\Column(name: 'parent_wording', type: Types::TEXT, nullable: true)]
    private ?string $parentWording = null;

    #[ORM\Column(length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $status = Lifecycle::DRAFT;

    #[ORM\Column(length: 20, options: ['default' => '1.0'])]
    private string $version = '1.0';

    public function __construct(Topic $topic)
    {
        $this->topic = $topic;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): Topic { return $this->topic; }
    public function setTeacherGuide(?string $v): void { $this->teacherGuide = $v; }
    public function setLearnerNote(?string $v): void { $this->learnerNote = $v; }
    public function setVideoUrl(?string $v): void { $this->videoUrl = $v; }
    public function setWorkedExamples(?string $v): void { $this->workedExamples = $v; }
    public function setParentWording(?string $v): void { $this->parentWording = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function setVersion(string $v): void { $this->version = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic->getId(),
            'topic' => $this->topic->getTitle(),
            'teacher_guide' => $this->teacherGuide,
            'learner_note' => $this->learnerNote,
            'video_url' => $this->videoUrl,
            'worked_examples' => $this->workedExamples,
            'parent_wording' => $this->parentWording,
            'status' => $this->status,
            'version' => $this->version,
        ];
    }
}
