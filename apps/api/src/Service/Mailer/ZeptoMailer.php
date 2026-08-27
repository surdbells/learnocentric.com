<?php

declare(strict_types=1);

namespace App\Service\Mailer;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transactional email via ZeptoMail's HTTP API.
 * https://www.zoho.com/zeptomail/help/api/email-sending.html
 */
class ZeptoMailer
{
    private Client $http;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $token,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client(['timeout' => 15]);
    }

    /**
     * @param array{address:string,name?:string} $to
     * @return bool true if accepted for delivery
     */
    public function send(array $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if ($this->token === '') {
            // No credentials configured, log and skip so dev flows don't fail.
            $this->logger->info('ZeptoMail skipped (no token configured)', ['to' => $to['address'] ?? null, 'subject' => $subject]);
            return false;
        }

        $payload = [
            'from' => ['address' => $this->fromAddress, 'name' => $this->fromName],
            'to' => [['email_address' => ['address' => $to['address'], 'name' => $to['name'] ?? $to['address']]]],
            'subject' => $subject,
            'htmlbody' => $htmlBody,
        ];
        if ($textBody !== null) {
            $payload['textbody'] = $textBody;
        }

        try {
            $this->http->post($this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Zoho-enczapikey ' . $this->token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('ZeptoMail send failed', ['error' => $e->getMessage(), 'subject' => $subject]);
            return false;
        }
    }
}
