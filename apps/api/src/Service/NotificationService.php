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
 * Email is best-effort, a delivery failure never blocks the triggering action.
 */
final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ZeptoMailer $mailer,
        private readonly PlatformSettings $settings,
    ) {
    }

    /** Is email switched on for the platform and actually configured? */
    public function emailAvailable(): bool
    {
        return $this->mailer->isConfigured() && $this->settings->bool('integrations.email_enabled');
    }

    /** The user's own "email me" preference (Settings → Notifications); on unless they turned it off. */
    public function wantsEmail(User $user): bool
    {
        $n = $user->getPreferences()['notifications'] ?? [];

        return (bool) ($n['email'] ?? $n['email_alerts'] ?? true);
    }

    /** Notification types a user can switch off, and the settings key that does it. */
    private const CATEGORY_PREFS = [
        'live_class' => 'live_class_reminders',
        'message' => 'message_notifications',
    ];

    /** False only when the user explicitly turned this category off (Settings → Notifications). */
    public function categoryEnabled(User $user, string $type): bool
    {
        $key = self::CATEGORY_PREFS[$type] ?? null;
        if ($key === null) {
            return true;
        }

        return ($user->getPreferences()['notifications'][$key] ?? true) !== false;
    }

    /** Email one user (no in-app record). Returns true if accepted for delivery. */
    public function email(User $user, string $title, ?string $message = null): bool
    {
        if ($user->getEmail() === '' || !$this->emailAvailable() || !$this->wantsEmail($user)) {
            return false;
        }
        // The school can switch email alerts off for all of its people (Settings → Notification Defaults).
        if (($user->getInstitution()?->getSettings()['notifications']['email_alerts'] ?? true) === false) {
            return false;
        }
        try {
            return $this->mailer->send(
                ['address' => $user->getEmail(), 'name' => $user->displayName()],
                $title,
                $this->htmlBody($user, $title, $message),
                $this->textBody($title, $message),
            );
        } catch (Throwable $e) {
            return false; // best-effort
        }
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
        // The user switched this kind of notification off in their settings:
        // hand back an unsaved object so callers needn't special-case it.
        if (!$this->categoryEnabled($user, $type)) {
            return $notification;
        }
        $this->em->persist($notification);

        if ($sendEmail) {
            // Best-effort; honours the platform email switch and the user's preference.
            $this->email($user, $title, $message);
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
