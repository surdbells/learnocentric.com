<?php

declare(strict_types=1);

namespace App\Service\Billing;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Paystack payment gateway client.
 * https://paystack.com/docs/api/
 */
class PaystackClient
{
    private Client $http;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $secretKey,
        private readonly string $callbackUrl,
    ) {
        $this->http = new Client(['timeout' => 20]);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Initialize a transaction. Returns Paystack's `data` (authorization_url, access_code, reference).
     *
     * @param int $amountKobo amount in the smallest currency unit (kobo for NGN)
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function initializeTransaction(string $email, int $amountKobo, ?string $reference = null, array $metadata = []): array
    {
        $this->assertConfigured();

        $payload = [
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'metadata' => $metadata,
        ];
        if ($reference !== null) {
            $payload['reference'] = $reference;
        }
        if ($this->callbackUrl !== '') {
            $payload['callback_url'] = $this->callbackUrl;
        }

        return $this->request('POST', '/transaction/initialize', $payload);
    }

    /** Verify a transaction by reference. @return array<string,mixed> Paystack `data`. */
    public function verifyTransaction(string $reference): array
    {
        $this->assertConfigured();

        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        $res = $this->http->request($method, $this->apiUrl . $path, $options);
        $body = json_decode((string) $res->getBody(), true);

        if (!is_array($body) || ($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack error: ' . ($body['message'] ?? 'unknown'));
        }

        return $body['data'] ?? [];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Paystack secret key is not configured (set PAYSTACK_SECRET_KEY).');
        }
    }
}
