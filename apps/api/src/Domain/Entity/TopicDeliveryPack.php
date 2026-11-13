<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use App\Service\Storage\FilePath;
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
class TopicDeliveryPack implements LifecycleAware
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

    /** Additional lesson media: array of {url, name}. Any type, video, audio, PDF, image. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $media = null;

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
    /**
     * The teacher guide is either an uploaded file (stored as a bare path, like
     * every other upload) or, on older packs, rich text.
     */
    public function setTeacherGuide(?string $v): void
    {
        $this->teacherGuide = ($v !== null && self::isFileReference($v)) ? FilePath::toPath($v) : $v;
    }

    /** True when the value is one uploaded file or link rather than written text. */
    public static function isFileReference(string $v): bool
    {
        $v = trim($v);
        if ($v === '' || preg_match('/\s|</', $v) === 1) {
            return false;
        }

        return preg_match('#^(https?://|/?backend/files|/?uploads/|\d{4}/\d{2}/[0-9a-f]{8,}\.[a-z0-9]{2,5}$)#i', $v) === 1;
    }
    public function setLearnerNote(?string $v): void { $this->learnerNote = $v; }
    public function setVideoUrl(?string $v): void { $this->videoUrl = FilePath::toPath($v); }
    public function getMedia(): array { return $this->media ?? []; }
    public function setMedia(?array $v): void
    {
        if ($v === null) { $this->media = null; return; }
        $clean = [];
        foreach ($v as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') { continue; }
            $clean[] = ['url' => FilePath::toPath($url) ?? $url, 'name' => trim((string) ($item['name'] ?? ''))];
        }
        $this->media = $clean === [] ? null : $clean;
    }
    public function setWorkedExamples(?string $v): void { $this->workedExamples = $v; }
    public function getParentWording(): ?string { return $this->parentWording; }
    public function setParentWording(?string $v): void { $this->parentWording = $v; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function setVersion(string $v): void { $this->version = $v; }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->status; }
    public function setLifecycleStatus(string $status): void { $this->status = $status; }
    public function lifecycleType(): string { return 'TopicDeliveryPack'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic_id' => $this->topic->getId(),
            'topic' => $this->topic->getTitle(),
            // Uploaded files leave here as full addresses the browser can load
            // from the API host; written text and outside links pass through.
            'teacher_guide' => ($this->teacherGuide !== null && self::isFileReference($this->teacherGuide))
                ? FilePath::toUrl($this->teacherGuide) : $this->teacherGuide,
            'teacher_guide_is_file' => $this->teacherGuide !== null && self::isFileReference($this->teacherGuide),
            'learner_note' => $this->learnerNote,
            'video_url' => FilePath::toUrl($this->videoUrl),
            'media' => array_map(
                static fn (array $m): array => ['url' => FilePath::toUrl((string) ($m['url'] ?? '')), 'name' => (string) ($m['name'] ?? '')],
                $this->media ?? [],
            ),
            'worked_examples' => $this->workedExamples,
            'parent_wording' => $this->parentWording,
            'status' => $this->status,
            'version' => $this->version,
        ];
    }
}
