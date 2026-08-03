<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Platform-wide configuration held as a single JSON row: general settings,
 * self-registration policy, feature flags, security policy and integration
 * toggles. Secrets are never stored here — only provider names and on/off state.
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_settings')]
class PlatformSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    /** @return array<string, mixed> */
    public function getData(): array { return $this->data ?? []; }

    /** @param array<string, mixed> $v */
    public function setData(array $v): void
    {
        $this->data = $v;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
