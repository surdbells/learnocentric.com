<?php

declare(strict_types=1);

namespace App\Service\Video;

use RuntimeException;

/**
 * Agora RTC token builder (AccessToken2, version "007").
 *
 * Unlike Daily.co there is no room REST resource: a live class is just a channel
 * name, and each participant joins with a short-lived RTC token generated here
 * from the App ID + App Certificate. Token generation is pure local crypto
 * (HMAC-SHA256 + zlib), no network call, mirroring Agora's official
 * RtcTokenBuilder2 / AccessToken2.
 *
 * @see https://docs.agora.io/en/video-calling/develop/authentication-workflow
 */
final class AgoraTokenService
{
    private const VERSION = '007';
    private const SERVICE_RTC = 1;
    // RTC privileges
    private const PRIV_JOIN_CHANNEL = 1;
    private const PRIV_PUBLISH_AUDIO = 2;
    private const PRIV_PUBLISH_VIDEO = 3;
    private const PRIV_PUBLISH_DATA = 4;

    public function __construct(
        private readonly string $appId,
        private readonly string $appCertificate,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appCertificate !== '';
    }

    public function appId(): string
    {
        return $this->appId;
    }

    /**
     * Build an RTC token for one participant of a channel.
     *
     * @param string $channel  The channel name (our live-class channel).
     * @param int    $uid      The participant's numeric uid (0 = any uid).
     * @param bool   $publisher Publishers may send audio/video/data; subscribers only receive.
     * @param int    $ttlSeconds Token lifetime in seconds from now.
     */
    public function rtcToken(string $channel, int $uid, bool $publisher, int $ttlSeconds = 7200): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Agora is not configured (set AGORA_APP_ID and AGORA_APP_CERTIFICATE).');
        }

        $issueTs = time();
        $salt = random_int(1, 99999999);

        // Privileges are TTLs (seconds from issue). Join always; publish only for hosts/speakers.
        $privileges = [self::PRIV_JOIN_CHANNEL => $ttlSeconds];
        if ($publisher) {
            $privileges[self::PRIV_PUBLISH_AUDIO] = $ttlSeconds;
            $privileges[self::PRIV_PUBLISH_VIDEO] = $ttlSeconds;
            $privileges[self::PRIV_PUBLISH_DATA] = $ttlSeconds;
        }

        // RTC service block: type + privilege map + channel + account (uid as string; "" for 0).
        $account = $uid === 0 ? '' : (string) $uid;
        $service = $this->packUint16(self::SERVICE_RTC)
            . $this->packMap($privileges)
            . $this->packString($channel)
            . $this->packString($account);

        // signing_info = appId + issueTs + expire + salt + serviceCount + service(s)
        $signingInfo = $this->packString($this->appId)
            . $this->packUint32($issueTs)
            . $this->packUint32($ttlSeconds)
            . $this->packUint32($salt)
            . $this->packUint16(1)
            . $service;

        // Signing key: HMAC(salt, HMAC(issueTs, appCertificate)); signature: HMAC(signingKey, signingInfo).
        $key1 = hash_hmac('sha256', $this->appCertificate, $this->packUint32($issueTs), true);
        $signingKey = hash_hmac('sha256', $key1, $this->packUint32($salt), true);
        $signature = hash_hmac('sha256', $signingInfo, $signingKey, true);

        $content = $this->packString($signature) . $signingInfo;

        return self::VERSION . base64_encode(gzcompress($content));
    }

    // --- little-endian packing helpers (match Agora's wire format) ---

    private function packUint16(int $n): string
    {
        return pack('v', $n);
    }

    private function packUint32(int $n): string
    {
        return pack('V', $n);
    }

    private function packString(string $s): string
    {
        return $this->packUint16(strlen($s)) . $s;
    }

    /** @param array<int,int> $map privilege => ttl */
    private function packMap(array $map): string
    {
        $out = $this->packUint16(count($map));
        foreach ($map as $key => $value) {
            $out .= $this->packUint16($key) . $this->packUint32($value);
        }
        return $out;
    }
}
