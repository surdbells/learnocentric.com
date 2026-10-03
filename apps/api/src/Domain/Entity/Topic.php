<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curriculum topic. Carries both the Academic Performance track and the
 * Competency Transfer track (spec §2), plus lifecycle/approval status.
 */
#[ORM\Entity]
#[ORM\Table(name: 'topics')]
#[ORM\HasLifecycleCallbacks]
class Topic implements LifecycleAware
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: SchoolClass::class)]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SchoolClass $schoolClass = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Term $term = null;

    #[ORM\Column(name: 'week_number', type: Types::SMALLINT, nullable: true)]
    private ?int $weekNumber = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $strand = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objective = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $prerequisites = null;

    // --- Academic Performance track ---
    #[ORM\Column(name: 'core_theory', type: Types::TEXT, nullable: true)]
    private ?string $coreTheory = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $misconceptions = null;

    #[ORM\Column(name: 'question_types', type: Types::JSON, nullable: true)]
    private ?array $questionTypes = null;

    // --- Competency Transfer track ---
    #[ORM\Column(name: 'real_life_relevance', type: Types::TEXT, nullable: true)]
    private ?string $realLifeRelevance = null;

    #[ORM\Column(name: 'workplace_relevance', type: Types::TEXT, nullable: true)]
    private ?string $workplaceRelevance = null;

    #[ORM\Column(name: 'competency_built', type: Types::TEXT, nullable: true)]
    private ?string $competencyBuilt = null;

    #[ORM\Column(name: 'portfolio_evidence_expected', type: Types::TEXT, nullable: true)]
    private ?string $portfolioEvidenceExpected = null;

    #[ORM\Column(name: 'approval_status', length: 20, options: ['default' => Lifecycle::DRAFT])]
    private string $approvalStatus = Lifecycle::DRAFT;

    public function __construct(Subject $subject, string $title)
    {
        $this->subject = $subject;
        $this->title = $title;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): Subject { return $this->subject; }
    public function getSchoolClass(): ?SchoolClass { return $this->schoolClass; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }
    public function setSchoolClass(?SchoolClass $v): void { $this->schoolClass = $v; }
    public function setTerm(?Term $v): void { $this->term = $v; }
    public function setWeekNumber(?int $v): void { $this->weekNumber = $v; }
    public function setStrand(?string $v): void { $this->strand = $v; }
    public function setObjective(?string $v): void { $this->objective = $v; }
    public function setCoreTheory(?string $v): void { $this->coreTheory = $v; }
    public function setRealLifeRelevance(?string $v): void { $this->realLifeRelevance = $v; }
    public function setCompetencyBuilt(?string $v): void { $this->competencyBuilt = $v; }
    public function getPortfolioEvidenceExpected(): ?string { return $this->portfolioEvidenceExpected; }
    public function setPortfolioEvidenceExpected(?string $v): void { $this->portfolioEvidenceExpected = $v; }
    public function getApprovalStatus(): string { return $this->approvalStatus; }
    public function setApprovalStatus(string $v): void { $this->approvalStatus = $v; }

    // --- LifecycleAware ---
    public function getLifecycleStatus(): string { return $this->approvalStatus; }
    public function setLifecycleStatus(string $status): void { $this->approvalStatus = $status; }
    public function lifecycleType(): string { return 'Topic'; }
    public function lifecycleSnapshot(): array { return $this->toArray(); }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject->getId(),
            'subject' => $this->subject->getName(),
            'class_id' => $this->schoolClass?->getId(),
            'class_label' => $this->schoolClass?->getLabel(),
            'term_id' => $this->term?->getId(),
            'week_number' => $this->weekNumber,
            'title' => $this->title,
            'strand' => $this->strand,
            'objective' => $this->objective,
            'prerequisites' => $this->prerequisites,
            'core_theory' => $this->coreTheory,
            'misconceptions' => $this->misconceptions,
            'question_types' => $this->questionTypes,
            'real_life_relevance' => $this->realLifeRelevance,
            'workplace_relevance' => $this->workplaceRelevance,
            'competency_built' => $this->competencyBuilt,
            'portfolio_evidence_expected' => $this->portfolioEvidenceExpected,
            'approval_status' => $this->approvalStatus,
        ];
    }
}
