<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\Billing\PaystackClient;
use App\Service\Mailer\ZeptoMailer;
use App\Service\PlatformSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/platform/settings, platform-wide configuration for the super admin:
 * general, self-registration policy, feature flags, security policy and
 * integration toggles. Stored as one JSON row (see PlatformSettings, which is
 * also what every feature reads); secrets are never kept here.
 */
final class SystemSettingsAction
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
        private readonly ZeptoMailer $mailer,
        private readonly PaystackClient $paystack,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (($deny = Guard::superAdmin($request, $response, 'Only the platform owner can view or change system settings.')) !== null) {
            return $deny;
        }
        return strtoupper($request->getMethod()) === 'PUT'
            ? $this->update($request, $response)
            : Json::write($response, $this->withStatus($this->settings->all()));
    }

    /**
     * Add what the server is actually able to do, so a switch that is on but
     * has no credentials behind it is visible instead of failing silently.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function withStatus(array $settings): array
    {
        return $settings + ['_status' => [
            'email_configured' => $this->mailer->isConfigured(),
            'payments_configured' => $this->paystack->isConfigured(),
        ]];
    }

    private function update(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $before = $this->settings->all();
        $current = $before;
        $body = (array) $request->getParsedBody();
        $defaults = PlatformSettings::DEFAULTS;

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

        if (is_array($body['registration'] ?? null)) { // includes allow_org_signup
            foreach (array_keys($defaults['registration']) as $k) {
                if (array_key_exists($k, $body['registration'])) {
                    $current['registration'][$k] = (bool) $body['registration'][$k];
                }
            }
        }

        if (is_array($body['feature_flags'] ?? null)) {
            foreach (array_keys($defaults['feature_flags']) as $k) {
                if (array_key_exists($k, $body['feature_flags'])) {
                    $current['feature_flags'][$k] = (bool) $body['feature_flags'][$k];
                }
            }
        }

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

        if (is_array($body['billing'] ?? null)) {
            $bill = $body['billing'];
            foreach (['enforce_user_limits', 'suspend_after_grace'] as $k) {
                if (array_key_exists($k, $bill)) {
                    $current['billing'][$k] = (bool) $bill[$k];
                }
            }
            if (array_key_exists('tax_percent', $bill)) {
                $current['billing']['tax_percent'] = round(max(0, min(100, (float) $bill['tax_percent'])), 2);
            }
        }

        if (is_array($body['integrations'] ?? null)) {
            $intg = $body['integrations'];
            foreach (['email_enabled', 'payments_enabled'] as $k) {
                if (array_key_exists($k, $intg)) {
                    $current['integrations'][$k] = (bool) $intg[$k];
                }
            }
            foreach (['email_provider', 'payment_provider'] as $k) {
                if (array_key_exists($k, $intg)) {
                    $current['integrations'][$k] = trim((string) $intg[$k]);
                }
            }
        }

        // Drop keys that are no longer settings (e.g. retired flags) so the row stays clean.
        foreach ($current as $section => $values) {
            $current[$section] = array_intersect_key($values, $defaults[$section] ?? []);
        }

        $row = $this->settings->save($current);
        $this->audit->log('platform.settings', $user, 'PlatformSetting', (string) $row->getId(), $before, $current);

        return Json::write($response, $this->withStatus($this->settings->all()));
    }
}
