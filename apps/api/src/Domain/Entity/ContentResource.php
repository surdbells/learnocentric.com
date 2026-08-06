<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reusable learning resource in the platform-wide content library (spec §8,
 * "content package supply chain"). Curated by the super admin, licensed, and
 * bundled into content packages for assignment to institutions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_resources')]
#[ORM\HasLifecycleCallbacks]
class ContentResource
{
    use TimestampsTrait;

    public const TYPES = ['document', 'video', 'assignment', 'quiz', 'interactive'];
    public const LICENCES = ['owned', 'cc-by', 'cc-by-sa', 'public-domain', 'licensed'];

    // Who the resource is intended for (spec §17 governance).
    public const AUDIENCES = ['learner', 'teacher', 'school_admin', 'platform'];

    // Publication state — only "published" resources are delivered to learners.
    public const VISIBILITIES = ['draft', 'internal', 'published'];

    // Licence review / takedown lifecycle.
    public const APPROVED = 'approved';
    public const PENDING = 'pending';
    public const TAKEDOWN = 'takedown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(name: 'content_type', length: 20)]
    private string $contentType = 'document';

    #[ORM\Column(name: 'subject_area', length: 120, nullable: true)]
    private ?string $subjectArea = null;

    /** Free-text grade / class label (e.g. "JSS 1"). */
    #[ORM\Column(name: 'grade_level', length: 60, nullable: true)]
    private ?string $gradeLevel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'difficulty_level', length: 20, nullable: true)]
    private ?string $difficultyLevel = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(name: 'is_premium', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isPremium = false;

    #[ORM\Column(name: 'file_url', length: 500, nullable: true)]
    private ?string $fileUrl = null;

    #[ORM\Column(name: 'file_name', length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(name: 'file_size', type: Types::INTEGER, nullable: true)]
    private ?int $fileSize = null;

    // --- Licensing / takedown register ---
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 20)]
    private string $licence = 'owned';

    #[ORM\Column(name: 'licence_status', length: 20)]
    private string $licenceStatus = self::APPROVED;

    #[ORM\Column(name: 'takedown_reason', type: Types::TEXT, nullable: true)]
    private ?string $takedownReason = null;

    // --- Resource governance (spec §17) ---
    #[ORM\Column(length: 20, options: ['default' => 'learner'])]
    private string $audience = 'learner';

    #[ORM\Column(length: 20, options: ['default' => 'published'])]
    private string $visibility = 'published';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $downloadable = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct(string $title, string $contentType = 'document')
    {
        $this->title = $title;
        $this->contentType = in_array($contentType, self::TYPES, true) ? $contentType : 'document';
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function getContentType(): string { return $this->contentType; }
    public function setContentType(string $v): void { $this->contentType = in_array($v, self::TYPES, true) ? $v : $this->contentType; }
    public function setSubjectArea(?string $v): void { $this->subjectArea = $v; }
    public function setGradeLevel(?string $v): void { $this->gradeLevel = $v; }
    public function setDescription(?string $v): void { $this->description = $v; }
    public function setDifficultyLevel(?string $v): void { $this->difficultyLevel = $v; }
    public function setTags(?array $v): void { $this->tags = $v; }
    public function setIsPremium(bool $v): void { $this->isPremium = $v; }
    public function setFile(?string $url, ?string $name, ?int $size): void
    {
        $this->fileUrl = \App\Service\Storage\FilePath::toPath($url);
        $this->fileName = $name;
        $this->fileSize = $size;
    }
    public function getSource(): ?string { return $this->source; }
    public function setSource(?string $v): void { $this->source = $v; }
    public function getLicence(): string { return $this->licence; }
    public function setLicence(string $v): void { $this->licence = in_array($v, self::LICENCES, true) ? $v : 'owned'; }
    public function getLicenceStatus(): string { return $this->licenceStatus; }
    public function setCreatedBy(?User $v): void { $this->createdBy = $v; }
    public function getAudience(): string { return $this->audience; }
    public function setAudience(string $v): void { $this->audience = in_array($v, self::AUDIENCES, true) ? $v : 'learner'; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $v): void { $this->visibility = in_array($v, self::VISIBILITIES, true) ? $v : 'published'; }
    public function isDownloadable(): bool { return $this->downloadable; }
    public function setDownloadable(bool $v): void { $this->downloadable = $v; }

    /** Take the resource down (licence dispute) or restore it. */
    public function takedown(?string $reason): void
    {
        $this->licenceStatus = self::TAKEDOWN;
        $this->takedownReason = $reason;
    }
    public function restore(): void
    {
        $this->licenceStatus = self::APPROVED;
        $this->takedownReason = null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'contentType' => $this->contentType,
            'subjectArea' => $this->subjectArea,
            'gradeLevel' => $this->gradeLevel,
            'description' => $this->description,
            'difficultyLevel' => $this->difficultyLevel,
            'tags' => $this->tags ?? [],
            'is_premium' => $this->isPremium,
            'file_url' => \App\Service\Storage\FilePath::toUrl($this->fileUrl),
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'source' => $this->source,
            'licence' => $this->licence,
            'licence_status' => $this->licenceStatus,
            'takedown_reason' => $this->takedownReason,
            'audience' => $this->audience,
            'visibility' => $this->visibility,
            'downloadable' => $this->downloadable,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
