<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\ContactEnquiry;
use App\Service\Mailer\ZeptoMailer;
use App\Service\PlatformSettings;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The public "Contact us" form.
 *  POST /contact                       (public) {name, email, organisation?, role?, message, website}
 *  GET  /platform/contact-enquiries    (platform owner) recent enquiries
 *  PUT  /platform/contact-enquiries/{id} {handled}
 *
 * Abuse controls for an unauthenticated endpoint: a honeypot field
 * ("website", which people never see), length limits, and a per-sender and
 * global hourly cap. Every accepted enquiry is stored; it is also emailed to
 * the platform's support address when email is configured.
 */
final class ContactAction
{
    private const PER_SENDER_PER_HOUR = 3;
    private const GLOBAL_PER_HOUR = 40;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZeptoMailer $mailer,
        private readonly PlatformSettings $settings,
        private readonly string $appSecret,
    ) {
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $ok = ['received' => true, 'message' => 'Thank you. We have your message and will be in touch.'];

        // Bots fill every field; people never see this one. Pretend it worked.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return Json::write($response, $ok, 201);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $message = trim((string) ($body['message'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return Json::error($response, 'Please tell us your name.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return Json::error($response, 'Please enter a valid email address so we can reply.', 422);
        }
        if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
            return Json::error($response, 'Please write a message of at least 10 characters (up to 5,000).', 422);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $ipHash = hash('sha256', $this->appSecret . '|contact|' . $ip);
        $since = new DateTimeImmutable('-1 hour');
        $count = fn (?string $hash): int => (int) (function () use ($hash, $since) {
            $qb = $this->em->createQueryBuilder()->select('COUNT(c.id)')->from(ContactEnquiry::class, 'c')
                ->where('c.createdAt > :since')->setParameter('since', $since);
            if ($hash !== null) {
                $qb->andWhere('c.ipHash = :h')->setParameter('h', $hash);
            }
            return $qb->getQuery()->getSingleScalarResult();
        })();
        if ($count($ipHash) >= self::PER_SENDER_PER_HOUR || $count(null) >= self::GLOBAL_PER_HOUR) {
            return Json::error($response, 'We have received several messages recently. Please try again in a little while.', 429);
        }

        $enquiry = new ContactEnquiry($name, $email, $message, $ipHash);
        $enquiry->setOrganisation($this->short($body['organisation'] ?? $body['school'] ?? null, 160));
        $enquiry->setRole($this->short($body['role'] ?? null, 60));
        $this->em->persist($enquiry);

        $to = trim((string) $this->settings->get('general.support_email', ''));
        if ($to !== '' && $this->mailer->isConfigured() && $this->settings->bool('integrations.email_enabled')) {
            $rows = [
                'Name' => $name, 'Email' => $email,
                'School / organisation' => $enquiry->getOrganisation() ?? '-', 'Role' => $enquiry->getRole() ?? '-',
            ];
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937"><h2 style="font-size:18px">New website enquiry</h2><table cellpadding="4">';
            foreach ($rows as $k => $v) {
                $html .= '<tr><td style="color:#6b7280">' . $k . '</td><td>' . htmlspecialchars($v, ENT_QUOTES) . '</td></tr>';
            }
            $html .= '</table><p style="white-space:pre-wrap;line-height:1.5">' . htmlspecialchars($message, ENT_QUOTES) . '</p><p style="color:#9ca3af;font-size:12px">Reply to ' . htmlspecialchars($email, ENT_QUOTES) . '.</p></div>';
            $text = "New website enquiry\n\n" . implode("\n", array_map(static fn ($k, $v) => "$k: $v", array_keys($rows), $rows)) . "\n\n" . $message;
            $enquiry->setEmailed($this->mailer->send(['address' => $to, 'name' => 'LearnoCentric Support'], 'Website enquiry from ' . $name, $html, $text));
        }
        $this->em->flush();

        return Json::write($response, $ok, 201);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($deny = Guard::superAdmin($request, $response, 'Only the platform owner can read website enquiries.')) !== null) {
            return $deny;
        }
        $rows = $this->em->getRepository(ContactEnquiry::class)->findBy([], ['id' => 'DESC'], 100);
        $open = count(array_filter($rows, static fn (ContactEnquiry $c) => !$c->toArray()['handled']));

        return Json::write($response, ['data' => array_map(static fn (ContactEnquiry $c) => $c->toArray(), $rows), 'meta' => ['open' => $open]]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($deny = Guard::superAdmin($request, $response, 'Only the platform owner can update website enquiries.')) !== null) {
            return $deny;
        }
        $enquiry = $this->em->getRepository(ContactEnquiry::class)->find((int) $args['id']);
        if ($enquiry === null) {
            return Json::error($response, 'Enquiry not found.', 404);
        }
        $enquiry->setHandled(filter_var(((array) $request->getParsedBody())['handled'] ?? true, FILTER_VALIDATE_BOOLEAN));
        $this->em->flush();

        return Json::write($response, $enquiry->toArray());
    }

    private function short(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
