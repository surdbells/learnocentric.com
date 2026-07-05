<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curated bundle of content-library resources the super admin sells/assigns
 * to institutions (spec §8). Institutions on an assigned package gain access to
 * its resources.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_packages')]
#[ORM\HasLifecycleCallbacks]
class ContentPackage
{
    use TimestampsTrait;

    public const TYPES = ['full_access', 'subject_pack', 'class_pack'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(name: 'package_type', length: 30)]
    private string $packageType = 'subject_pack';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'subject_area', length: 120, nullable: true)]
    private ?string $subjectArea = null;

    /** The catalogue subject this package is scoped to. */
    #[ORM\ManyToOne(targetEntity: CatalogSubject::class)]
    #[ORM\JoinColumn(name: 'catalog_subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CatalogSubject $catalogSubject = null;

    #[ORM\Column(name: 'grade_level', length: 60, nullable: true)]
    private ?string $gradeLevel = null;

    /** Price in kobo. */
    #[ORM\Column(name: 'price_kobo', type: Types::INTEGER, options: ['default' => 0])]
    private int $priceKobo = 0;

    #[ORM\Column(name: 'duration_months', type: Types::INTEGER, nullable: true)]
    private ?int $durationMonths = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, ContentResource> */
    #[ORM\ManyToMany(targetEntity: ContentResource::class)]
    #[ORM\JoinTable(name: 'content_package_resources')]
    private Collection $resources;

    public function __construct(string $name, string $packageType = 'subject_pack')
    {
        $this->name = $name;
        $this->packageType = in_array($packageType, self::TYPES, true) ? $packageType : 'subject_pack';
        $this->resources = new ArrayCollection();
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function setPackageType(string $v): void { $this->packageType = in_array($v, self::TYPES, true) ? $v : $this->packageType; }
    public function setDescription(?string $v): void { $this->description = $v; }
    public function setSubjectArea(?string $v): void { $this->subjectArea = $v; }
    public function getCatalogSubject(): ?CatalogSubject { return $this->catalogSubject; }
    public function setCatalogSubject(?CatalogSubject $v): void { $this->catalogSubject = $v; }
    public function setGradeLevel(?string $v): void { $this->gradeLevel = $v; }
    public function getPriceKobo(): int { return $this->priceKobo; }
    public function setPriceKobo(int $v): void { $this->priceKobo = max(0, $v); }
    public function setDurationMonths(?int $v): void { $this->durationMonths = $v; }
    public function setIsActive(bool $v): void { $this->isActive = $v; }

    /** @return Collection<int, ContentResource> */
    public function getResources(): Collection { return $this->resources; }
    public function addResource(ContentResource $r): void { if (!$this->resources->contains($r)) { $this->resources->add($r); } }
    public function clearResources(): void { $this->resources->clear(); }

    public function toArray(bool $withContent = false): array
    {
        $out = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->packageType,
            'package_type' => $this->packageType,
            'description' => $this->description,
            'subjectArea' => $this->subjectArea,
            'catalog_subject_id' => $this->catalogSubject?->getId(),
            'catalog_subject' => $this->catalogSubject?->getName(),
            'grade_level' => $this->gradeLevel,
            'gradeLevel' => $this->gradeLevel,
            'price' => $this->priceKobo / 100,
            'durationMonths' => $this->durationMonths,
            'isActive' => $this->isActive,
            'content_count' => $this->resources->count(),
            'contentIds' => $this->resources->map(fn (ContentResource $r) => $r->getId())->getValues(),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
        if ($withContent) {
            $out['content'] = $this->resources->map(fn (ContentResource $r) => $r->toArray())->getValues();
        }
        return $out;
    }
}
