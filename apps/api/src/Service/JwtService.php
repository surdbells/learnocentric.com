<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\User;
use RuntimeException;

/**
 * Minimal, dependency-free HS256 JWT encode/decode.
 * Token payload mirrors the legacy backend for frontend compatibility.
 */
class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
        private readonly string $issuer,
    ) {
    }

    /**
     * @param int|null $ttlSeconds session length; defaults to JWT_TTL. Callers
     *                             pass the platform's inactivity timeout, and
     *                             the auth middleware re-issues the token while
     *                             the user stays active (sliding expiry).
     */
    public function issueForUser(User $user, ?int $ttlSeconds = null): string
    {
        $now = time();
        $ttl = $ttlSeconds ?? $this->ttl;

        return $this->encode([
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $ttl,
            'ttl' => $ttl,
            'userId' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->effectiveCode(),
            'institutionId' => $user->getInstitution()?->getId(),
        ]);
    }

    /**
     * A 5-minute token proving the password step passed, exchanged for a real
     * session at /auth/2fa/verify. It carries `purpose`, which the auth
     * middleware refuses, so it can't be used as a session token.
     */
    public function issueTwoFactorChallenge(User $user): string
    {
        $now = time();

        return $this->encode([
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + 300,
            'purpose' => '2fa',
            'userId' => $user->getId(),
        ]);
    }

    /** @return int user id from a valid, unexpired 2FA challenge */
    public function decodeTwoFactorChallenge(string $token): int
    {
        $claims = $this->decode($token);
        if (($claims['purpose'] ?? null) !== '2fa' || (int) ($claims['userId'] ?? 0) <= 0) {
            throw new RuntimeException('Not a two-factor challenge.');
        }

        return (int) $claims['userId'];
    }

    /** @param array<string,mixed> $payload */
    public function encode(array $payload): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->b64($this->sign("{$header}.{$body}"));

        return "{$header}.{$body}.{$signature}";
    }

    /** @return array<string,mixed> */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$header, $body, $signature] = $parts;

        $expected = $this->b64($this->sign("{$header}.{$body}"));
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $payload = json_decode($this->b64decode($body), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid token payload.');
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw new RuntimeException('Token expired.');
        }

        return $payload;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
