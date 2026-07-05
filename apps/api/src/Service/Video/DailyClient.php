<?php

declare(strict_types=1);

namespace App\Service\Video;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Daily.co REST client for live virtual classes.
 * https://docs.daily.co/reference/rest-api
 */
class DailyClient
{
    private Client $http;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
    ) {
        $this->http = new Client(['timeout' => 15]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Create a room. Returns the Daily room payload (includes `name` and `url`).
     *
     * @param array<string,mixed> $properties Daily room properties (e.g. exp, enable_recording)
     * @return array<string,mixed>
     */
    public function createRoom(?string $name = null, array $properties = []): array
    {
        $this->assertConfigured();

        $body = ['properties' => $properties];
        if ($name !== null) {
            $body['name'] = $name;
        }

        $res = $this->http->post($this->apiUrl . '/rooms', [
            'headers' => $this->headers(),
            'json' => $body,
        ]);

        return json_decode((string) $res->getBody(), true);
    }

    /**
     * Create a meeting token (for private rooms / owner privileges).
     *
     * @param array<string,mixed> $properties e.g. ['room_name' => ..., 'is_owner' => true, 'user_name' => ...]
     */
    public function createMeetingToken(array $properties): string
    {
        $this->assertConfigured();

        $res = $this->http->post($this->apiUrl . '/meeting-tokens', [
            'headers' => $this->headers(),
            'json' => ['properties' => $properties],
        ]);

        $data = json_decode((string) $res->getBody(), true);

        return $data['token'] ?? '';
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Daily.co API key is not configured (set DAILY_API_KEY).');
        }
    }
}
