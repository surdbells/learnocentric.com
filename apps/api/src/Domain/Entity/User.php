<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 190, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'first_name', length: 100)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 100)]
    private string $lastName;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(name: 'admission_number', length: 60, nullable: true)]
    private ?string $admissionNumber = null;

    /** Onboarding blob captured on creation: guardian, support notes, consent, placement extras. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $onboarding = null;

    #[ORM\Column(name: 'date_of_birth', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateOfBirth = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institution $institution = null;

    #[ORM\Column(name: 'profile_image_url', length: 1024, nullable: true)]
    private ?string $profileImageUrl = null;

    #[ORM\Column(length: 30, options: ['default' => 'active'])]
    private string $status = 'active';

    /** Per-user settings blob (notifications, appearance, privacy, role prefs). Never holds secrets. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $preferences = null;

    #[ORM\Column(name: 'last_login', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastLogin = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $firstName, string $lastName, Role $role)
    {
        $this->email = strtolower($email);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = strtolower($email); }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function setPasswordHash(string $hash): void { $this->passwordHash = $hash; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): void { $this->firstName = $v; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): void { $this->lastName = $v; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): void { $this->phone = $v; }
    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $v): void { $this->gender = ($v === null || trim($v) === '') ? null : $v; }
    public function getAdmissionNumber(): ?string { return $this->admissionNumber; }
    public function setAdmissionNumber(?string $v): void { $this->admissionNumber = ($v === null || trim($v) === '') ? null : $v; }
    public function getOnboarding(): ?array { return $this->onboarding; }
    public function setOnboarding(?array $v): void { $this->onboarding = $v; }
    public function getDateOfBirth(): ?\DateTimeInterface { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeInterface $v): void { $this->dateOfBirth = $v; }
    public function getRole(): Role { return $this->role; }
    public function setRole(Role $role): void { $this->role = $role; }
    public function getInstitution(): ?Institution { return $this->institution; }
    public function setInstitution(?Institution $i): void { $this->institution = $i; }
    public function getProfileImageUrl(): ?string { return $this->profileImageUrl; }
    public function setProfileImageUrl(?string $v): void { $this->profileImageUrl = \App\Service\Storage\FilePath::toPath($v); }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): void { $this->status = $v; }
    public function getPreferences(): ?array { return $this->preferences; }
    public function setPreferences(?array $v): void { $this->preferences = $v; }
    public function getLastLogin(): ?DateTimeImmutable { return $this->lastLogin; }
    public function markLoggedIn(): void { $this->lastLogin = new DateTimeImmutable(); }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    /** Public shape consumed by the Angular frontend (AuthUser). */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'admissionNumber' => $this->admissionNumber,
            'dateOfBirth' => $this->dateOfBirth?->format('Y-m-d'),
            'role' => $this->role->getCode(),
            'institutionId' => $this->institution?->getId(),
            'profileImageUrl' => \App\Service\Storage\FilePath::toUrl($this->profileImageUrl),
            'status' => $this->status,
        ];
    }
}
