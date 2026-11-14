<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Entity\Concern\TimestampsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A subscription plan an organisation can pay for (spec §17).
 *
 * A plan buys one organisation account and its agreed user limits. It has a
 * standard billing period (a school term, a month, or one fixed cohort) and may
 * also be offered for a year at its own price. A "custom" plan is quoted and
 * agreed in a contract, so it cannot be bought online.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_plans')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
{
    use TimestampsTrait;

    /** "cohort" is one fixed block of teaching weeks (see periodDays). */
    public const INTERVALS = ['monthly', 'termly', 'yearly', 'cohort'];

    /** Which kind of organisation a plan is sold to. */
    public const AUDIENCES = ['school', 'academy'];

    /** Length of a cohort when the plan does not say (six teaching weeks). */
    public const DEFAULT_COHORT_DAYS = 42;

    /**
     * Gateable feature modules a plan may grant (core features are always on).
     * Safeguarding used to be listed here; it is core now and never gated.
     */
    public const MODULES = [
        'assessments', 'worksheets', 'portfolio', 'live_classes',
        'analytics', 'interventions',
    ];

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

    #[ORM\Column(length: 20, options: ['default' => 'school'])]
    private string $audience = 'school';

    /** Price for one standard period, in kobo (NGN). */
    #[ORM\Column(name: 'price_kobo', type: Types::INTEGER)]
    private int $priceKobo;

    /** The standard billing period. */
    #[ORM\Column(length: 20)]
    private string $interval = 'termly';

    /** Price for a year paid up front, in kobo. Null means annual billing is not offered. */
    #[ORM\Column(name: 'annual_price_kobo', type: Types::INTEGER, nullable: true)]
    private ?int $annualPriceKobo = null;

    #[ORM\Column(name: 'max_students', type: Types::INTEGER, nullable: true)]
    private ?int $maxStudents = null;

    #[ORM\Column(name: 'max_teachers', type: Types::INTEGER, nullable: true)]
    private ?int $maxTeachers = null;

    #[ORM\Column(name: 'max_admins', type: Types::INTEGER, nullable: true)]
    private ?int $maxAdmins = null;

    /** Allowance for the organisation's own uploaded files, in GB. Null means not set. */
    #[ORM\Column(name: 'storage_gb', type: Types::INTEGER, nullable: true)]
    private ?int $storageGb = null;

    /** Quoted and agreed in a contract: shown, but not purchasable online. */
    #[ORM\Column(name: 'is_custom', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCustom = false;

    /** Fixed length of one period in days, for cohort plans. */
    #[ORM\Column(name: 'period_days', type: Types::INTEGER, nullable: true)]
    private ?int $periodDays = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    /** Feature modules this plan unlocks; a subset of self::MODULES. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $modules = null;

    /** On sale. An inactive plan is a draft (or retired): existing subscribers keep it, nobody new can buy it. */
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
    public function getAudience(): string { return $this->audience; }
    public function setAudience(string $v): void { $this->audience = in_array($v, self::AUDIENCES, true) ? $v : 'school'; }
    public function getPriceKobo(): int { return $this->priceKobo; }
    public function setPriceKobo(int $v): void { $this->priceKobo = max(0, $v); }
    public function getInterval(): string { return $this->interval; }
    public function setInterval(string $v): void { $this->interval = in_array($v, self::INTERVALS, true) ? $v : 'termly'; }
    public function getAnnualPriceKobo(): ?int { return $this->annualPriceKobo; }
    public function setAnnualPriceKobo(?int $v): void { $this->annualPriceKobo = $v === null || $v <= 0 ? null : $v; }
    public function getMaxStudents(): ?int { return $this->maxStudents; }
    public function setMaxStudents(?int $v): void { $this->maxStudents = $v; }
    public function getMaxTeachers(): ?int { return $this->maxTeachers; }
    public function setMaxTeachers(?int $v): void { $this->maxTeachers = $v; }
    public function getMaxAdmins(): ?int { return $this->maxAdmins; }
    public function setMaxAdmins(?int $v): void { $this->maxAdmins = $v; }
    public function getStorageGb(): ?int { return $this->storageGb; }
    public function setStorageGb(?int $v): void { $this->storageGb = $v; }
    public function isCustom(): bool { return $this->isCustom; }
    public function setIsCustom(bool $v): void { $this->isCustom = $v; }
    public function getPeriodDays(): ?int { return $this->periodDays; }
    public function setPeriodDays(?int $v): void { $this->periodDays = $v === null || $v <= 0 ? null : $v; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): void { $this->sortOrder = $v; }
    public function setFeatures(?array $v): void { $this->features = $v; }

    /** @return string[] the granted module keys (validated subset of self::MODULES). */
    public function getModules(): array
    {
        return array_values(array_intersect(self::MODULES, $this->modules ?? []));
    }

    public function setModules(?array $v): void
    {
        $this->modules = $v === null ? null : array_values(array_intersect(self::MODULES, $v));
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): void { $this->isActive = $v; }

    /** Can this plan be paid for online right now? */
    public function isPurchasable(): bool
    {
        return $this->isActive && !$this->isCustom && $this->priceKobo > 0;
    }

    /**
     * The billing periods this plan is offered for: its standard one, plus a
     * year when an annual price has been set.
     *
     * @return string[]
     */
    public function intervalsOffered(): array
    {
        $out = [$this->interval];
        if ($this->annualPriceKobo !== null && $this->interval !== 'yearly') {
            $out[] = 'yearly';
        }

        return $out;
    }

    /** The fee for one period of the given kind, in kobo, or null when the plan is not offered that way. */
    public function priceFor(string $interval): ?int
    {
        if ($interval === $this->interval) {
            return $this->priceKobo;
        }
        if ($interval === 'yearly') {
            return $this->annualPriceKobo;
        }

        return null;
    }

    /**
     * When a period of the given kind that starts at $start ends.
     *
     * A month runs to the same day of the next month, or to the month's last day
     * when that date does not exist (31 January to 28 February). A term is four
     * months and a year twelve, by the same rule. A cohort is a fixed number of days.
     */
    public function periodEndFrom(\DateTimeImmutable $start, string $interval): \DateTimeImmutable
    {
        return match ($interval) {
            'monthly' => self::addMonths($start, 1),
            'yearly' => self::addMonths($start, 12),
            'cohort' => $start->modify('+' . ($this->periodDays ?? self::DEFAULT_COHORT_DAYS) . ' days'),
            default => self::addMonths($start, 4), // a school term
        };
    }

    /** Add calendar months, keeping the day of the month where it exists and using the month's last day where it does not. */
    public static function addMonths(\DateTimeImmutable $from, int $months): \DateTimeImmutable
    {
        $day = (int) $from->format('j');
        $target = $from->modify('first day of this month')->modify(sprintf('%+d months', $months));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, (int) $target->format('t')));
    }

    /** Period length for one paid cycle of the plan's standard period (kept for callers that only need a rough length). */
    public function periodInterval(): \DateInterval
    {
        return match ($this->interval) {
            'monthly' => new \DateInterval('P1M'),
            'yearly' => new \DateInterval('P1Y'),
            'cohort' => new \DateInterval('P' . ($this->periodDays ?? self::DEFAULT_COHORT_DAYS) . 'D'),
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
            'audience' => $this->audience,
            'price_kobo' => $this->priceKobo,
            'price_naira' => $this->priceKobo / 100,
            'interval' => $this->interval,
            'annual_price_kobo' => $this->annualPriceKobo,
            'annual_price_naira' => $this->annualPriceKobo !== null ? $this->annualPriceKobo / 100 : null,
            'intervals' => $this->intervalsOffered(),
            'max_students' => $this->maxStudents,
            'max_teachers' => $this->maxTeachers,
            'max_admins' => $this->maxAdmins,
            'storage_gb' => $this->storageGb,
            'is_custom' => $this->isCustom,
            'period_days' => $this->periodDays,
            'sort_order' => $this->sortOrder,
            'features' => $this->features,
            'modules' => $this->getModules(),
            'all_modules' => self::MODULES,
            'is_active' => $this->isActive,
            'purchasable' => $this->isPurchasable(),
        ];
    }
}
