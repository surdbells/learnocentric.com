<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable snapshot recorded on each lifecycle transition of a content item
 * (spec §13 versioning): who moved it, from/to state, and the content at that point.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_versions')]
#[ORM\Index(name: 'idx_cv_entity', columns: ['entity_type', 'entity_id'])]
class ContentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'entity_type', length: 60)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id')]
    private int $entityId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version;

    #[ORM\Column(name: 'from_status', length: 20, nullable: true)]
    private ?string $fromStatus = null;

    #[ORM\Column(name: 'to_status', length: 20)]
    private string $toStatus;

    #[ORM\Column(length: 40)]
    private string $action;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $snapshot = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'actor_id', nullable: true)]
    private ?int $actorId = null;

    #[ORM\Column(name: 'actor_name', length: 190, nullable: true)]
    private ?string $actorName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $entityType, int $entityId, int $version, string $toStatus, string $action)
    {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->version = $version;
        $this->toStatus = $toStatus;
        $this->action = $action;
        $this->createdAt = new DateTimeImmutable();
    }

    public function setFromStatus(?string $v): void { $this->fromStatus = $v; }
    public function setSnapshot(?array $v): void { $this->snapshot = $v; }
    public function setNote(?string $v): void { $this->note = $v; }
    public function setActor(?int $id, ?string $name): void { $this->actorId = $id; $this->actorName = $name; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'action' => $this->action,
            'note' => $this->note,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
