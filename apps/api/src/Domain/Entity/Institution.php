<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'institutions')]
#[ORM\HasLifecycleCallbacks]
class Institution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 50, options: ['default' => 'school'])]
    private string $type = 'school';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'logo_url', length: 1024, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $branding = null;

    #[ORM\Column(name: 'admin_contact', type: Types::JSON, nullable: true)]
    private ?array $adminContact = null;

    /** Institution settings: grading policy + safeguarding config. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(name: 'assigned_package_id', nullable: true)]
    private ?int $assignedPackageId = null;

    /** When the current package was assigned; a package with a duration expires that long after this. */
    #[ORM\Column(name: 'assigned_package_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $assignedPackageAt = null;

    #[ORM\Column(length: 30, options: ['default' => 'active'])]
    private string $status = 'active';

    /** Learners/parents may self-register into this school with its join code. */
    #[ORM\Column(name: 'self_signup', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $selfSignup = false;

    /** How the organisation came to the platform: "platform" (set up by the platform owner) or "self" (signed itself up). */
    #[ORM\Column(name: 'signup_source', length: 20, options: ['default' => 'platform'])]
    private string $signupSource = 'platform';

    /** A self-signed-up organisation stays closed, except for billing, until its first payment is verified. */
    #[ORM\Column(name: 'awaiting_payment', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $awaitingPayment = false;

    /** Code people enter on the public sign-up page; never the raw institution id. */
    #[ORM\Column(name: 'join_code', length: 16, unique: true, nullable: true)]
    private ?string $joinCode = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): void { $this->type = $type; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): void { $this->address = $address; }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): void { $this->logoUrl = \App\Service\Storage\FilePath::toPath($logoUrl); }
    public function getBranding(): ?array { return $this->branding; }
    public function setBranding(?array $branding): void { $this->branding = $branding; }

    /** The school's brand colour. Stored as branding.primary_color ("color" was a second, older key). */
    public function getBrandColor(): ?string
    {
        $c = $this->branding['primary_color'] ?? $this->branding['color'] ?? null;

        return is_string($c) && $c !== '' ? $c : null;
    }

    public function setBrandColor(?string $color): void
    {
        $branding = $this->branding ?? [];
        unset($branding['color'], $branding['primary_color']);
        if ($color !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($color))) {
            $branding['primary_color'] = strtolower(trim($color));
        }
        $this->branding = $branding !== [] ? $branding : null;
    }
    public function getAdminContact(): ?array { return $this->adminContact; }
    public function setAdminContact(?array $adminContact): void { $this->adminContact = $adminContact; }
    public function getStatus(): string { return $this->status; }

    /** Academies are stored as "tutoring_academy"; older rows may say "academy". */
    public function isAcademy(): bool { return in_array($this->type, ['tutoring_academy', 'academy'], true); }

    /** The administrator role an organisation of this kind is run by. */
    public function adminRoleCode(): string { return $this->isAcademy() ? Role::TUTOR_ADMIN : Role::SCHOOL_ADMIN; }

    public function getSignupSource(): string { return $this->signupSource; }
    public function setSignupSource(string $v): void { $this->signupSource = in_array($v, ['platform', 'self'], true) ? $v : 'platform'; }
    public function isAwaitingPayment(): bool { return $this->awaitingPayment; }
    public function setAwaitingPayment(bool $v): void { $this->awaitingPayment = $v; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getAssignedPackageId(): ?int { return $this->assignedPackageId; }
    public function setAssignedPackageId(?int $id): void
    {
        if ($id !== $this->assignedPackageId) {
            // A new assignment starts a fresh term; clearing it clears the clock.
            $this->assignedPackageAt = $id === null ? null : new DateTimeImmutable();
        }
        $this->assignedPackageId = $id;
    }
    public function getAssignedPackageAt(): ?DateTimeImmutable { return $this->assignedPackageAt; }
    /** Start the package's term again from today (a renewal of the same package). */
    public function renewAssignedPackage(): void
    {
        if ($this->assignedPackageId !== null) {
            $this->assignedPackageAt = new DateTimeImmutable();
        }
    }
    public function getSettings(): ?array { return $this->settings; }

    /** The school's time zone (Settings → Localisation), used for anything that happens "on a day". */
    public function timeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) ($this->settings['localization']['timezone'] ?? 'Africa/Lagos'));
        } catch (\Throwable) {
            return new \DateTimeZone('Africa/Lagos');
        }
    }
    public function setSettings(?array $settings): void { $this->settings = $settings; }
    public function isSelfSignup(): bool { return $this->selfSignup; }
    public function setSelfSignup(bool $v): void { $this->selfSignup = $v; }
    public function getJoinCode(): ?string { return $this->joinCode; }

    /** New random join code (no ambiguous characters like 0/O, 1/I). */
    public function regenerateJoinCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $this->joinCode = $code;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'address' => $this->address,
            'logo_url' => \App\Service\Storage\FilePath::toUrl($this->logoUrl),
            'branding' => $this->branding,
            'brand_color' => $this->getBrandColor(),
            'primary_color' => $this->getBrandColor(),
            'admin_contact' => $this->adminContact,
            'settings' => $this->settings,
            'assigned_package_id' => $this->assignedPackageId,
            'assigned_package_at' => $this->assignedPackageAt?->format(DATE_ATOM),
            'status' => $this->status,
            'signup_source' => $this->signupSource,
            'awaiting_payment' => $this->awaitingPayment,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
