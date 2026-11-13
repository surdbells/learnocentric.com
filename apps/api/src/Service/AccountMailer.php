<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\AuthToken;
use App\Domain\Entity\User;
use App\Service\Mailer\ZeptoMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Account emails that carry a single-use link: password reset and email
 * verification. Issues the token, builds the link into the web app, and sends
 * it. With no mail provider configured (local development) the link is
 * written to the app log instead so the flow can still be exercised.
 */
class AccountMailer
{
    private const RESET_TTL = '+1 hour';
    private const VERIFY_TTL = '+3 days';
    private const MAX_PER_HOUR = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZeptoMailer $mailer,
        private readonly PlatformSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly string $webUrl,
        private readonly bool $devMode,
    ) {
    }

    /** Can account emails actually reach people right now? */
    public function canDeliver(): bool
    {
        return $this->mailer->isConfigured() && $this->settings->bool('integrations.email_enabled');
    }

    public function sendPasswordReset(User $user): bool
    {
        $link = $this->issue($user, AuthToken::PASSWORD_RESET, self::RESET_TTL, '/reset-password');
        if ($link === null) {
            return false;
        }

        return $this->deliver(
            $user,
            'Reset your LearnoCentric password',
            'We received a request to reset the password for your account. This link works once and expires in 1 hour.',
            'Choose a new password',
            $link,
            'If you didn\'t ask for this, you can ignore this email; your password stays the same.',
        );
    }

    public function sendEmailVerification(User $user): bool
    {
        $link = $this->issue($user, AuthToken::EMAIL_VERIFY, self::VERIFY_TTL, '/verify-email');
        if ($link === null) {
            return false;
        }

        return $this->deliver(
            $user,
            'Confirm your email for LearnoCentric',
            'Welcome! Confirm this is your email address to finish creating your account. The link expires in 3 days.',
            'Confirm my email',
            $link,
            'If you didn\'t create a LearnoCentric account, you can ignore this email.',
        );
    }

    /** Find a usable token of the given purpose, or null. */
    public function find(string $plainToken, string $purpose): ?AuthToken
    {
        if ($plainToken === '') {
            return null;
        }
        /** @var AuthToken|null $token */
        $token = $this->em->getRepository(AuthToken::class)->findOneBy(['tokenHash' => AuthToken::hash($plainToken), 'purpose' => $purpose]);

        return $token !== null && $token->isUsable() ? $token : null;
    }

    /** Mark every outstanding token of this purpose for the user as used. */
    public function revoke(User $user, string $purpose): void
    {
        foreach ($this->em->getRepository(AuthToken::class)->findBy(['user' => $user, 'purpose' => $purpose, 'usedAt' => null]) as $t) {
            /** @var AuthToken $t */
            $t->markUsed();
        }
    }

    /** Issue a token and return the web link, or null when rate-limited. */
    private function issue(User $user, string $purpose, string $ttl, string $path): ?string
    {
        $recent = (int) $this->em->createQueryBuilder()->select('COUNT(t.id)')->from(AuthToken::class, 't')
            ->where('t.user = :u')->andWhere('t.purpose = :p')->andWhere('t.createdAt > :since')
            ->setParameter('u', $user)->setParameter('p', $purpose)->setParameter('since', new DateTimeImmutable('-1 hour'))
            ->getQuery()->getSingleScalarResult();
        if ($recent >= self::MAX_PER_HOUR) {
            return null;
        }
        $this->revoke($user, $purpose);
        $plain = bin2hex(random_bytes(32));
        $this->em->persist(new AuthToken($user, $purpose, $plain, new DateTimeImmutable($ttl)));
        $this->em->flush();

        return rtrim($this->webUrl, '/') . $path . '?token=' . $plain;
    }

    private function deliver(User $user, string $subject, string $intro, string $button, string $link, string $footer): bool
    {
        if (!$this->canDeliver()) {
            if ($this->devMode) {
                // Local development only: no mail provider, so surface the link in var/log/app.log.
                $this->logger->info('Account email not sent (mail not configured); dev link', ['to' => $user->getEmail(), 'subject' => $subject, 'link' => $link]);
            } else {
                $this->logger->warning('Account email not sent: mail is not configured or is switched off', ['subject' => $subject]);
            }
            return false;
        }
        $name = htmlspecialchars($user->getFirstName(), ENT_QUOTES);
        $safeLink = htmlspecialchars($link, ENT_QUOTES);
        $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2937">
  <div style="background:#39c645;padding:16px 24px;border-radius:12px 12px 0 0">
    <span style="color:#fff;font-size:18px;font-weight:700">LearnoCentric</span>
  </div>
  <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 12px 12px;padding:24px">
    <p style="margin:0 0 12px">Hi {$name},</p>
    <p style="color:#4b5563;line-height:1.5">{$intro}</p>
    <p style="margin:24px 0"><a href="{$safeLink}" style="background:#16a34a;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:700;display:inline-block">{$button}</a></p>
    <p style="color:#6b7280;font-size:13px;line-height:1.5">Or paste this link into your browser:<br><span style="word-break:break-all">{$safeLink}</span></p>
    <p style="color:#9ca3af;font-size:12px;margin-top:24px">{$footer}</p>
  </div>
</div>
HTML;

        return $this->mailer->send(
            ['address' => $user->getEmail(), 'name' => $user->displayName()],
            $subject,
            $html,
            "Hi {$user->getFirstName()},\n\n{$intro}\n\n{$button}: {$link}\n\n{$footer}",
        );
    }
}
