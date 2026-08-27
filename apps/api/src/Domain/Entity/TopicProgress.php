<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Tracks a student viewing a topic's lesson, the entry point of the topic journey. */
#[ORM\Entity]
#[ORM\Table(name: 'topic_progress')]
#[ORM\UniqueConstraint(name: 'uniq_topic_student_progress', columns: ['topic_id', 'student_id'])]
#[ORM\HasLifecycleCallbacks]
class TopicProgress
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'lesson_viewed', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $lessonViewed = false;

    #[ORM\Column(name: 'lesson_viewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lessonViewedAt = null;

    public function __construct(Topic $topic, User $student)
    {
        $this->topic = $topic;
        $this->student = $student;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getTopic(): Topic { return $this->topic; }
    public function getStudent(): User { return $this->student; }
    public function isLessonViewed(): bool { return $this->lessonViewed; }
    public function setLessonViewed(bool $v): void { $this->lessonViewed = $v; }
    public function getLessonViewedAt(): ?\DateTimeImmutable { return $this->lessonViewedAt; }
    public function setLessonViewedAt(?\DateTimeImmutable $v): void { $this->lessonViewedAt = $v; }
}
