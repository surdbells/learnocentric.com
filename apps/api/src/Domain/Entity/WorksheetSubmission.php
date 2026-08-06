<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A student's submission for a worksheet, with the teacher's score and feedback. */
#[ORM\Entity]
#[ORM\Table(name: 'worksheet_submissions')]
#[ORM\UniqueConstraint(name: 'uniq_worksheet_student', columns: ['worksheet_id', 'student_id'])]
#[ORM\HasLifecycleCallbacks]
class WorksheetSubmission
{
    use TimestampsTrait;

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const GRADED = 'graded';
    public const RETURNED = 'returned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Worksheet::class)]
    #[ORM\JoinColumn(name: 'worksheet_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Worksheet $worksheet;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'response_text', type: Types::TEXT, nullable: true)]
    private ?string $responseText = null;

    #[ORM\Column(name: 'attachment_url', length: 500, nullable: true)]
    private ?string $attachmentUrl = null;

    #[ORM\Column(length: 20)]
    private string $status = self::SUBMITTED;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedback = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'graded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $gradedAt = null;

    public function __construct(Worksheet $worksheet, User $student)
    {
        $this->worksheet = $worksheet;
        $this->student = $student;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getWorksheet(): Worksheet { return $this->worksheet; }
    public function getStudent(): User { return $this->student; }
    public function getResponseText(): ?string { return $this->responseText; }
    public function setResponseText(?string $v): void { $this->responseText = $v; }
    public function getAttachmentUrl(): ?string { return $this->attachmentUrl; }
    public function setAttachmentUrl(?string $v): void { $this->attachmentUrl = \App\Service\Storage\FilePath::toPath($v); }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getScore(): ?int { return $this->score; }
    public function setScore(?int $v): void { $this->score = $v; }
    public function getFeedback(): ?string { return $this->feedback; }
    public function setFeedback(?string $v): void { $this->feedback = $v; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $v): void { $this->submittedAt = $v; }
    public function getGradedAt(): ?\DateTimeImmutable { return $this->gradedAt; }
    public function setGradedAt(?\DateTimeImmutable $v): void { $this->gradedAt = $v; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'worksheet_id' => $this->worksheet->getId(),
            'worksheet' => $this->worksheet->getTitle(),
            'total_marks' => $this->worksheet->getTotalMarks(),
            'student_id' => $this->student->getId(),
            'student' => $this->student->getFirstName() . ' ' . $this->student->getLastName(),
            'response_text' => $this->responseText,
            'attachment_url' => \App\Service\Storage\FilePath::toUrl($this->attachmentUrl),
            'status' => $this->status,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'submitted_at' => $this->submittedAt?->format(DATE_ATOM),
            'graded_at' => $this->gradedAt?->format(DATE_ATOM),
        ];
    }
}
