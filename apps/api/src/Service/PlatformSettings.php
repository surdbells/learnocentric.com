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
