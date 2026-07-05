<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A SaaS subscription plan institutions can pay for (spec §17). */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_plans')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
{
    use TimestampsTrait;

    public const INTERVALS = ['monthly', 'termly', 'yearly'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Price in kobo (NGN smallest unit). */
    #[ORM\Column(name: 'price_kobo', type: Types::INTEGER)]
    private int $priceKobo;

    #[ORM\Column(length: 20)]
    private string $interval = 'termly';

    #[ORM\Column(name: 'max_students', type: Types::INTEGER, nullable: true)]
    private ?int $maxStudents = null;

    #[ORM\Column(name: 'max_teachers', type: Types::INTEGER, nullable: true)]
    private ?int $maxTeachers = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    public function __construct(string $code, string $name, int $priceKobo)
    {
        $this->code = $code;
        $this->name = $name;
        $this->priceKobo = $priceKobo;
        $this->initTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function setDescription(?string $v): void { $this->description = $v; }
    public function getPriceKobo(): int { return $this->priceKobo; }
    public function setPriceKobo(int $v): void { $this->priceKobo = max(0, $v); }
    public function getInterval(): string { return $this->interval; }
    public function setInterval(string $v): void { $this->interval = in_array($v, self::INTERVALS, true) ? $v : 'termly'; }
    public function setMaxStudents(?int $v): void { $this->maxStudents = $v; }
    public function setMaxTeachers(?int $v): void { $this->maxTeachers = $v; }
    public function setFeatures(?array $v): void { $this->features = $v; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): void { $this->isActive = $v; }

    /** Period length for one paid cycle. */
    public function periodInterval(): \DateInterval
    {
        return match ($this->interval) {
            'monthly' => new \DateInterval('P1M'),
            'yearly' => new \DateInterval('P1Y'),
            default => new \DateInterval('P4M'), // a school term
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_kobo' => $this->priceKobo,
            'price_naira' => $this->priceKobo / 100,
            'interval' => $this->interval,
            'max_students' => $this->maxStudents,
            'max_teachers' => $this->maxTeachers,
            'features' => $this->features,
            'is_active' => $this->isActive,
        ];
    }
}
