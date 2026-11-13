<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's own progress on a learning resource: whether they've marked it
 * done, and their private notes. One row per user + resource.
 */
#[ORM\Entity]
#[ORM\Table(name: 'resource_progress')]
#[ORM\UniqueConstraint(name: 'uniq_resource_progress', columns: ['user_id', 'resource_id'])]
class ResourceProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ContentResource::class)]
    #[ORM\JoinColumn(name: 'resource_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ContentResource $resource;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $done = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(User $user, ContentResource $resource)
    {
        $this->user = $user;
        $this->resource = $resource;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isDone(): bool { return $this->done; }
    public function setDone(bool $v): void { $this->done = $v; $this->updatedAt = new DateTimeImmutable(); }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): void
    {
        $this->notes = ($v === null || trim($v) === '') ? null : mb_substr($v, 0, 20000);
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resource_id' => $this->resource->getId(),
            'done' => $this->done,
            'notes' => $this->notes ?? '',
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
