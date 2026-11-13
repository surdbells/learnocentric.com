<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use, expiring token emailed to a user: password reset or email
 * verification. Only the SHA-256 of the token is stored, so a database leak
 * doesn't hand out working links.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_tokens')]
#[ORM\Index(name: 'idx_auth_tokens_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_auth_tokens_user', columns: ['user_id', 'purpose'])]
class AuthToken
{
    public const PASSWORD_RESET = 'password_reset';
    public const EMAIL_VERIFY = 'email_verify';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30)]
    private string $purpose;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, string $purpose, string $plainToken, DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->purpose = $purpose;
        $this->tokenHash = self::hash($plainToken);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getUser(): User { return $this->user; }
    public function getPurpose(): string { return $this->purpose; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function isUsable(): bool { return $this->usedAt === null && $this->expiresAt > new DateTimeImmutable(); }
    public function markUsed(): void { $this->usedAt = new DateTimeImmutable(); }
}
