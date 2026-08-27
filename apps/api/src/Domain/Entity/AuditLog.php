<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable record of sensitive actions (logins, role changes, score edits,
 * exports, settings changes), required by the spec for accountability.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'idx_audit_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_audit_object', columns: ['object_type', 'object_id'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'institution_id', nullable: true)]
    private ?int $institutionId = null;

    #[ORM\Column(name: 'action_type', length: 100)]
    private string $actionType;

    #[ORM\Column(name: 'object_type', length: 100, nullable: true)]
    private ?string $objectType = null;

    #[ORM\Column(name: 'object_id', length: 100, nullable: true)]
    private ?string $objectId = null;

    #[ORM\Column(name: 'old_value', type: Types::JSON, nullable: true)]
    private ?array $oldValue = null;

    #[ORM\Column(name: 'new_value', type: Types::JSON, nullable: true)]
    private ?array $newValue = null;

    #[ORM\Column(name: 'ip_device', length: 255, nullable: true)]
    private ?string $ipDevice = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $actionType)
    {
        $this->actionType = $actionType;
        $this->createdAt = new DateTimeImmutable();
    }

    public function setUserId(?int $v): void { $this->userId = $v; }
    public function setInstitutionId(?int $v): void { $this->institutionId = $v; }
    public function setObject(?string $type, ?string $id): void { $this->objectType = $type; $this->objectId = $id; }
    public function setOldValue(?array $v): void { $this->oldValue = $v; }
    public function setNewValue(?array $v): void { $this->newValue = $v; }
    public function setIpDevice(?string $v): void { $this->ipDevice = $v; }
}
