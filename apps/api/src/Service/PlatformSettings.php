<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\PlatformSetting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Platform-wide settings the super admin edits under System Settings, stored as
 * one JSON row merged over these defaults. This is the single reader every
 * feature uses, so a toggle in the UI actually changes behaviour.
 */
class PlatformSettings
{
    public const DEFAULTS = [
        'general' => [
            'platform_name' => 'LearnoCentric',
            'support_email' => '',
            'default_locale' => 'en',
            'maintenance_mode' => false,
            'maintenance_message' => '',
        ],
        'registration' => [
            // Master switch: schools can only open join-code sign-up when this is on.
            'allow_self_signup' => false,
            'require_email_verification' => true,
        ],
        'feature_flags' => [
            // Turning a flag off removes that module for every institution.
            'live_classes' => true,
            'portfolio' => true,
            'messaging' => true,
            'analytics' => true,
            'parent_portal' => true,
        ],
        'security' => [
            'password_min_length' => 8,
            'session_timeout_minutes' => 60,
            'enforce_2fa' => false,
            // Custom roles' permission matrix is enforced on every route.
            'rbac_enforce' => true,
        ],
        'billing' => [
            // A plan's user limits stop another login being enabled once its places are used.
            'enforce_user_limits' => true,
            // Tutors and learners are suspended from day eight after the last paid day.
            'suspend_after_grace' => true,
            // Tax added to every order, as a percentage of the fee (0 shows no tax line).
            'tax_percent' => 0,
        ],
        'integrations' => [
            'email_enabled' => true,
            'email_provider' => 'ZeptoMail',
            'payments_enabled' => true,
            'payment_provider' => 'Paystack',
        ],
    ];

    /** @var array<string,mixed>|null per-request cache */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** All settings, stored values merged over defaults (full shape). */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = $this->row()->getData();
        $out = [];
        foreach (self::DEFAULTS as $section => $defaults) {
            $saved = is_array($stored[$section] ?? null) ? array_intersect_key($stored[$section], $defaults) : [];
            $out[$section] = array_merge($defaults, $saved);
        }
        return $this->cache = $out;
    }

    /** Read one value by "section.key", e.g. get('security.password_min_length'). */
    public function get(string $path, mixed $default = null): mixed
    {
        [$section, $key] = array_pad(explode('.', $path, 2), 2, null);
        return $this->all()[$section][$key] ?? $default;
    }

    public function bool(string $path): bool
    {
        return (bool) $this->get($path, false);
    }

    /** Minimum password length for every place a password is set (never below 6). */
    public function passwordMinLength(): int
    {
        return max(6, (int) $this->get('security.password_min_length', 8));
    }

    /** How long a session lasts without activity, in seconds (5 minutes to 7 days). */
    public function sessionSeconds(): int
    {
        return max(5, min(10080, (int) $this->get('security.session_timeout_minutes', 60))) * 60;
    }

    /**
     * Why this user can't use the platform right now, or null if they can:
     * maintenance mode (everyone but the platform owner) or the parent portal
     * being switched off.
     *
     * @return array{0:int,1:string,2:string}|null [http status, code, message]
     */
    public function blockFor(\App\Domain\Entity\User $user): ?array
    {
        $role = $user->getRole()->effectiveCode();
        if ($role === 'super_admin') {
            return null;
        }
        if ($this->bool('general.maintenance_mode')) {
            $msg = trim((string) $this->get('general.maintenance_message', ''));

            return [503, 'maintenance', $msg !== '' ? $msg : 'The platform is down for maintenance. Please try again shortly.'];
        }
        if ($role === 'parent' && !$this->bool('feature_flags.parent_portal')) {
            return [403, 'portal_disabled', 'The parent portal is currently switched off.'];
        }

        return null;
    }

    public function save(array $data): PlatformSetting
    {
        $row = $this->row();
        $row->setData($data);
        $this->em->flush();
        $this->cache = null;
        return $row;
    }

    /** The singleton settings row, created on first access. */
    public function row(): PlatformSetting
    {
        $row = $this->em->getRepository(PlatformSetting::class)->findOneBy([], ['id' => 'ASC']);
        if ($row === null) {
            $row = new PlatformSetting();
            $this->em->persist($row);
            $this->em->flush();
        }
        return $row;
    }
}
