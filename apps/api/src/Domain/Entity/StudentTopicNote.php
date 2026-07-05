<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A learner's personal notes for a topic, written while studying it. */
#[ORM\Entity]
#[ORM\Table(name: 'student_topic_notes')]
#[ORM\UniqueConstraint(name: 'uniq_student_topic_note', columns: ['student_id', 'topic_id'])]
class StudentTopicNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Topic $topic;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $student, Topic $topic)
    {
        $this->student = $student;
        $this->topic = $topic;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBody(): string { return $this->body; }
    public function setBody(string $v): void
    {
        $this->body = $v;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
