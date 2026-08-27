<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET  /backend/auth/settings, profile summary + role-aware preferences (defaults merged over stored).
 * PUT  /backend/auth/settings, persist a type-checked merge of the incoming preferences.
 *
 * Preferences are a single JSON blob on the user; unknown keys are ignored and
 * every scalar is cast to the type of its default, so the store can't be polluted.
 */
final class UserSettingsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $defaults = $this->defaultsFor($user->getRole()->getCode());

        if (strtoupper($request->getMethod()) === 'PUT') {
            $incoming = (array) ($request->getParsedBody()['preferences'] ?? $request->getParsedBody() ?? []);
            $before = $user->getPreferences();
            $storedTree = $this->mergeTyped($defaults, $defaults, (array) $before);
            $merged = $this->mergeTyped($defaults, $storedTree, $incoming);
            $user->setPreferences($merged);
            $this->em->flush();
            $this->audit->log('user.settings.update', $user, 'User', (string) $user->getId(), ['preferences' => $before], ['preferences' => $merged]);

            return Json::write($response, ['preferences' => $merged, 'message' => 'Settings saved.']);
        }

        $preferences = $this->mergeTyped($defaults, $defaults, (array) $user->getPreferences());
        $ua = $request->getHeaderLine('User-Agent');

        return Json::write($response, [
            'profile' => [
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => trim($user->getFirstName() . ' ' . $user->getLastName()),
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'role' => $user->getRole()->getCode(),
                'profileImageUrl' => $user->getProfileImageUrl(),
                'staffId' => $user->getId() ? sprintf('LRN-%s-%04d', strtoupper(substr($user->getRole()->getCode(), 0, 2)), $user->getId()) : null,
            ],
            'security' => [
                'last_login' => $user->getLastLogin()?->format(DATE_ATOM),
                'active_session' => $this->describeSession($ua),
                'two_factor' => (bool) ($preferences['privacy']['two_factor'] ?? false),
            ],
            'preferences' => $preferences,
        ]);
    }

    /** Role-aware default preference tree. Sections not relevant to a role are simply omitted. */
    private function defaultsFor(string $role): array
    {
        $base = [
            'notifications' => [
                'push' => true,
                'email' => true,
                'sms' => false,
                'live_class_reminders' => true,
            ],
            'privacy' => [
                'profile_visibility' => 'tutors_only',
                'data_usage_wifi_only' => false,
                'two_factor' => false,
            ],
            'language' => [
                'app' => 'en',
                'content' => 'en',
            ],
        ];

        if ($role === 'teacher') {
            $base['notifications'] = [
                'assignment_alerts' => true,
                'submission_reviews' => true,
                'live_class_reminders' => true,
                'message_notifications' => true,
                'report_generation_alerts' => true,
                'parent_communication' => true,
            ];
            $base['grading'] = [
                'default_scale' => 'percentage',
                'auto_sync' => true,
                'require_confirm' => true,
                'show_audit' => true,
                'rubric_pref' => 'use_rubrics',
                'late_handling' => 'deduct_auto',
            ];
            $base['communication'] = [
                'default_landing' => 'class_dashboard',
                'announcement_visibility' => 'all_classes',
                'parent_copy' => 'include',
                'signature' => '',
            ];
            return $base;
        }

        if (in_array($role, ['school_admin', 'tutor_admin'], true)) {
            $base['notifications'] = [
                'email_alerts' => true,
                'report_notifications' => true,
                'parent_communication' => true,
            ];
            return $base;
        }

        // learner (student), the fullest personal preference set
        $base['learning'] = [
            'daily_reminder' => true,
            'daily_reminder_time' => '19:00',
            'weekly_summary' => true,
        ];
        $base['appearance'] = [
            'theme' => 'system',
            'text_size' => 'medium',
        ];
        return $base;
    }

    /**
     * Build a result where, for every section/key present in $schema, the value comes
     * from $override if it supplies a same-typed scalar, else from $stored, else the schema default.
     * Unknown keys in the overriding arrays are ignored, the schema is the whitelist.
     */
    private function mergeTyped(array $schema, array $stored, array $override): array
    {
        $out = [];
        foreach ($schema as $section => $defaults) {
            if (!is_array($defaults)) {
                $out[$section] = $this->castLike($defaults, $override[$section] ?? $stored[$section] ?? $defaults);
                continue;
            }
            $out[$section] = [];
            $storedSection = is_array($stored[$section] ?? null) ? $stored[$section] : [];
            $overrideSection = is_array($override[$section] ?? null) ? $override[$section] : [];
            foreach ($defaults as $key => $default) {
                if (array_key_exists($key, $overrideSection)) {
                    $out[$section][$key] = $this->castLike($default, $overrideSection[$key]);
                } elseif (array_key_exists($key, $storedSection)) {
                    $out[$section][$key] = $this->castLike($default, $storedSection[$key]);
                } else {
                    $out[$section][$key] = $default;
                }
            }
        }
        return $out;
    }

    /** Cast $value to the scalar type of $default (bool/int/string); strings are trimmed + length-capped. */
    private function castLike(mixed $default, mixed $value): mixed
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }
        if (is_int($default)) {
            return is_numeric($value) ? (int) $value : $default;
        }
        return mb_substr(trim((string) $value), 0, 2000);
    }

    private function describeSession(string $ua): string
    {
        $browser = 'Browser';
        foreach (['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $label) {
            if (str_contains($ua, $needle)) { $browser = $label; break; }
        }
        $os = 'Unknown OS';
        foreach (['Windows' => 'Windows', 'Mac' => 'macOS', 'Android' => 'Android', 'iPhone' => 'iOS', 'Linux' => 'Linux'] as $needle => $label) {
            if (str_contains($ua, $needle)) { $os = $label; break; }
        }
        return $browser . ' on ' . $os;
    }
}
