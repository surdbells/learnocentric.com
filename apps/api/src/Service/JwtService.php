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

    public function issueForUser(User $user): string
    {
        $now = time();

        return $this->encode([
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'userId' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->getCode(),
            'institutionId' => $user->getInstitution()?->getId(),
        ]);
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
