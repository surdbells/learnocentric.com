<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Service\Mailer\ZeptoMailer;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Creates in-app notifications and mirrors them to email (ZeptoMail).
 * Email is best-effort — a delivery failure never blocks the triggering action.
 */
final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZeptoMailer $mailer,
    ) {
    }

    /**
     * Record a notification for a user (persisted with the caller's unit of work)
     * and email them. Returns the entity so the caller can flush.
     */
    public function notify(User $user, string $type, string $title, ?string $message = null, ?string $link = null, bool $sendEmail = true): Notification
    {
        $notification = new Notification($user, $type, $title);
        $notification->setMessage($message);
        $notification->setLink($link);
        $this->em->persist($notification);

        if ($sendEmail && $user->getEmail() !== '') {
            try {
                $this->mailer->send(
                    ['address' => $user->getEmail(), 'name' => trim($user->getFirstName() . ' ' . $user->getLastName())],
                    $title,
                    $this->htmlBody($user, $title, $message),
                    $this->textBody($title, $message),
                );
            } catch (Throwable $e) {
                // best-effort — swallow so the triggering action still succeeds
            }
        }

        return $notification;
    }

    private function htmlBody(User $user, string $title, ?string $message): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES);
        $safeMessage = $message !== null ? nl2br(htmlspecialchars($message, ENT_QUOTES)) : '';
        $name = htmlspecialchars($user->getFirstName(), ENT_QUOTES);

        return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1f2937">
  <div style="background:#39c645;padding:16px 24px;border-radius:12px 12px 0 0">
    <span style="color:#fff;font-size:18px;font-weight:700">LearnoCentric</span>
  </div>
  <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 12px 12px;padding:24px">
    <p style="margin:0 0 12px">Hi {$name},</p>
    <h2 style="font-size:18px;margin:0 0 8px">{$safeTitle}</h2>
    <p style="color:#4b5563;line-height:1.5">{$safeMessage}</p>
    <p style="color:#9ca3af;font-size:12px;margin-top:24px">You are receiving this because you have a LearnoCentric account.</p>
  </div>
</div>
HTML;
    }

    private function textBody(string $title, ?string $message): string
    {
        return trim($title . "\n\n" . ($message ?? ''));
    }
}
