<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\User;
use DateTimeImmutable;
use RuntimeException;

/**
 * RFC 6238 time-based one-time passwords (30 s, 6 digits, SHA-1), the scheme
 * every authenticator app (Google Authenticator, Microsoft Authenticator,
 * Authy, 1Password…) supports. Secrets are encrypted at rest with a key
 * derived from the app's JWT secret. Backup codes are stored password-hashed.
 */
class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1;            // accept ±1 step for clock drift
    private const MAX_FAILURES = 5;      // then lock for LOCK_MINUTES
    private const LOCK_MINUTES = 15;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private readonly string $key;

    public function __construct(string $appSecret, private readonly string $issuer = 'LearnoCentric')
    {
        $this->key = sodium_crypto_generichash('totp-secret-v1|' . $appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** A fresh random 160-bit secret, base32 encoded (what authenticator apps expect). */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** otpauth:// URI for the QR code. */
    public function uri(User $user, string $secret): string
    {
        $label = rawurlencode($this->issuer) . ':' . rawurlencode($user->getEmail());

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $this->issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->key));
    }

    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Corrupt two-factor secret.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plain === false) {
            throw new RuntimeException('Two-factor secret could not be decrypted (was JWT_SECRET changed?).');
        }

        return $plain;
    }

    /** Is the user temporarily locked out after too many wrong codes? */
    public function isLocked(User $user): bool
    {
        $at = $user->getTotpFailedAt();

        return $user->getTotpFailures() >= self::MAX_FAILURES
            && $at !== null
            && $at > new DateTimeImmutable('-' . self::LOCK_MINUTES . ' minutes');
    }

    /**
     * Check a 6-digit code (or a backup code) for the user's stored secret.
     * Records the step (anti-replay) or the failure on the entity; the caller flushes.
     */
    public function verifyUser(User $user, string $code, bool $allowBackup = true): bool
    {
        if ($user->getTotpSecret() === null || $this->isLocked($user)) {
            return false;
        }
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code)) {
            $step = $this->matchStep($this->decrypt($user->getTotpSecret()), $code, $user->getTotpLastStep());
            if ($step !== null) {
                $user->setTotpLastStep($step);
                $user->clearTotpFailures();
                return true;
            }
        } elseif ($allowBackup && $this->consumeBackupCode($user, $code)) {
            $user->clearTotpFailures();
            return true;
        }

        $user->recordTotpFailure();
        return false;
    }

    /** Verify a code against a plain secret (enrolment, before it's stored as enabled). */
    public function verifySecret(string $secret, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return preg_match('/^\d{6}$/', $code) ? $this->matchStep($secret, $code, null) : null;
    }

    /**
     * Issue 10 fresh backup codes (shown to the user once) and store their hashes.
     *
     * @return string[] plain codes, formatted xxxx-xxxx
     */
    public function issueBackupCodes(User $user): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = strtolower(bin2hex(random_bytes(4)));
            $plain[] = substr($c, 0, 4) . '-' . substr($c, 4, 4);
            $hashes[] = password_hash($c, PASSWORD_DEFAULT);
        }
        $user->setTotpBackupCodes($hashes);

        return $plain;
    }

    private function consumeBackupCode(User $user, string $code): bool
    {
        $norm = strtolower(str_replace('-', '', $code));
        if (!preg_match('/^[0-9a-f]{8}$/', $norm)) {
            return false;
        }
        $hashes = $user->getTotpBackupCodes();
        foreach ($hashes as $i => $hash) {
            if (password_verify($norm, $hash)) {
                unset($hashes[$i]);
                $user->setTotpBackupCodes($hashes);
                return true;
            }
        }
        return false;
    }

    private function matchStep(string $secret, string $code, ?int $lastStep): ?int
    {
        $key = $this->base32Decode($secret);
        $now = intdiv(time(), self::PERIOD);
        for ($d = -self::WINDOW; $d <= self::WINDOW; $d++) {
            $step = $now + $d;
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // already used (replay)
            }
            if (hash_equals($this->hotp($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    private function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N2', $counter >> 32, $counter & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $v = strpos(self::BASE32, $ch);
            if ($v === false) {
                throw new RuntimeException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** Test helper: the current code for a plain secret. */
    public function currentCode(string $secret, int $offsetSteps = 0): string
    {
        return $this->hotp($this->base32Decode($secret), intdiv(time(), self::PERIOD) + $offsetSteps);
    }
}
