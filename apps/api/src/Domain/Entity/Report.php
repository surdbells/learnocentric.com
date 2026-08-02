<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A generated platform report: a persisted snapshot of a templated aggregation
 * (overview, institution performance, subscriptions, growth) so the super admin
 * has a durable, exportable history rather than only ad-hoc CSV pulls.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reports')]
#[ORM\Index(name: 'idx_reports_type', columns: ['type'])]
class Report
{
    public const READY = 'ready';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(length: 200)]
    private string $title;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $params = null;

    /** @var array<int, array{label:string, value:mixed}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $summary = null;

    /** @var array{columns: array<int,string>, rows: array<int, array<int, mixed>>}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\Column(length: 20)]
    private string $status = self::READY;

    #[ORM\Column(name: 'generated_by_id', nullable: true)]
    private ?int $generatedById = null;

    #[ORM\Column(name: 'generated_by_name', length: 150, nullable: true)]
    private ?string $generatedByName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $type, string $title)
    {
        $this->type = $type;
        $this->title = $title;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }

    public function setParams(?array $v): void { $this->params = $v; }
    public function setSummary(?array $v): void { $this->summary = $v; }
    public function setData(?array $v): void { $this->data = $v; }
    public function setGeneratedBy(?int $id, ?string $name): void { $this->generatedById = $id; $this->generatedByName = $name; }

    /** @return array<string, mixed> */
    public function toArray(bool $withData = false): array
    {
        $base = [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'params' => $this->params,
            'summary' => $this->summary,
            'generated_by' => $this->generatedByName,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'row_count' => isset($this->data['rows']) ? count($this->data['rows']) : 0,
        ];
        if ($withData) {
            $base['data'] = $this->data;
        }
        return $base;
    }
}
