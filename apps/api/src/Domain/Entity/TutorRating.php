<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A learner's rating of a tutor (1–5) with an optional comment. One per pair. */
#[ORM\Entity]
#[ORM\Table(name: 'tutor_ratings')]
#[ORM\UniqueConstraint(name: 'uniq_student_tutor', columns: ['student_id', 'tutor_id'])]
#[ORM\HasLifecycleCallbacks]
class TutorRating
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tutor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $tutor;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rating;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function __construct(User $student, User $tutor, int $rating)
    {
        $this->student = $student;
        $this->tutor = $tutor;
        $this->rating = max(1, min(5, $rating));
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getRating(): int { return $this->rating; }
    public function setRating(int $v): void { $this->rating = max(1, min(5, $v)); }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): void { $this->comment = $v; }
}
