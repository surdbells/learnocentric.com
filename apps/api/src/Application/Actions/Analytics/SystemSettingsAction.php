<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Json;
use App\Domain\Entity\PlatformSetting;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/platform/settings, platform-wide configuration for the super admin:
 * general, self-registration policy, feature flags, security policy and
 * integration toggles. Stored as one JSON row; secrets are never kept here.
 */
final class SystemSettingsAction
{
    private const DEFAULTS = [
        'general' => [
            'platform_name' => 'LearnoCentric',
            'support_email' => '',
            'default_locale' => 'en',
            'maintenance_mode' => false,
            'maintenance_message' => '',
        ],
        'registration' => [
            'allow_self_signup' => false,
            'require_email_verification' => true,
        ],
        'feature_flags' => [
            'live_classes' => true,
            'portfolio' => true,
            'messaging' => true,
            'analytics' => true,
            'ai_grading' => false,
            'parent_portal' => true,
        ],
        'security' => [
            'password_min_length' => 8,
            'session_timeout_minutes' => 60,
            'enforce_2fa' => false,
            'rbac_enforce' => false,
        ],
        'integrations' => [
            'email_enabled' => false,
            'email_provider' => '',
            'sms_enabled' => false,
            'sms_provider' => '',
            'payments_enabled' => true,
            'payment_provider' => 'Paystack',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return strtoupper($request->getMethod()) === 'PUT'
            ? $this->update($request, $response)
            : $this->show($request, $response);
    }

    private function show(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response, false)) !== null) {
            return $g;
        }
        return Json::write($response, $this->merged());
    }

    private function update(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response, true)) !== null) {
            return $g;
        }
        /** @var User $user */
        $user = $request->getAttribute('user');
        $before = $this->merged();
        $body = (array) $request->getParsedBody();
        $current = $this->merged();

        // General.
        if (is_array($body['general'] ?? null)) {
            $gen = $body['general'];
            foreach (['platform_name', 'support_email', 'default_locale', 'maintenance_message'] as $k) {
                if (array_key_exists($k, $gen)) {
                    $current['general'][$k] = trim((string) $gen[$k]);
                }
            }
            if (array_key_exists('maintenance_mode', $gen)) {
                $current['general']['maintenance_mode'] = (bool) $gen['maintenance_mode'];
            }
        }

        // Registration (booleans).
        if (is_array($body['registration'] ?? null)) {
            foreach (['allow_self_signup', 'require_email_verification'] as $k) {
                if (array_key_exists($k, $body['registration'])) {
                    $current['registration'][$k] = (bool) $body['registration'][$k];
                }
            }
        }

        // Feature flags (booleans, only known keys).
        if (is_array($body['feature_flags'] ?? null)) {
            foreach (array_keys(self::DEFAULTS['feature_flags']) as $k) {
                if (array_key_exists($k, $body['feature_flags'])) {
                    $current['feature_flags'][$k] = (bool) $body['feature_flags'][$k];
                }
            }
        }

        // Security (clamped ints + booleans).
        if (is_array($body['security'] ?? null)) {
            $sec = $body['security'];
            if (array_key_exists('password_min_length', $sec)) {
                $current['security']['password_min_length'] = max(6, min(64, (int) $sec['password_min_length']));
            }
            if (array_key_exists('session_timeout_minutes', $sec)) {
                $current['security']['session_timeout_minutes'] = max(5, min(1440, (int) $sec['session_timeout_minutes']));
            }
            foreach (['enforce_2fa', 'rbac_enforce'] as $k) {
                if (array_key_exists($k, $sec)) {
                    $current['security'][$k] = (bool) $sec[$k];
                }
            }
        }

        // Integrations (provider names + enabled toggles; never secrets).
        if (is_array($body['integrations'] ?? null)) {
            $intg = $body['integrations'];
            foreach (['email_enabled', 'sms_enabled', 'payments_enabled'] as $k) {
                if (array_key_exists($k, $intg)) {
                    $current['integrations'][$k] = (bool) $intg[$k];
                }
            }
            foreach (['email_provider', 'sms_provider', 'payment_provider'] as $k) {
                if (array_key_exists($k, $intg)) {
                    $current['integrations'][$k] = trim((string) $intg[$k]);
                }
            }
        }

        $row = $this->settingsRow();
        $row->setData($current);
        $this->em->flush();
        $this->audit->log('platform.settings', $user, 'PlatformSetting', (string) $row->getId(), $before, $current);

        return Json::write($response, $current);
    }

    /** Stored settings merged over the defaults so the client always gets a full shape. */
    private function merged(): array
    {
        $stored = $this->settingsRow()->getData();
        $out = [];
        foreach (self::DEFAULTS as $section => $defaults) {
            $out[$section] = array_merge($defaults, is_array($stored[$section] ?? null) ? $stored[$section] : []);
        }
        return $out;
    }

    /** The singleton settings row, created on first access. */
    private function settingsRow(): PlatformSetting
    {
        $row = $this->em->getRepository(PlatformSetting::class)->findOneBy([], ['id' => 'ASC']);
        if ($row === null) {
            $row = new PlatformSetting();
            $this->em->persist($row);
            $this->em->flush();
        }
        return $row;
    }

    private function guard(Request $request, Response $response, bool $write): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform owner can view or change system settings.', 403);
        }
        return null;
    }
}
