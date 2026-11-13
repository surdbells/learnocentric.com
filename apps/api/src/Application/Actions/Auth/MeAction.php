<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\SubscriptionPlan;
use App\Domain\Entity\User;
use App\Service\ModuleAccess;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/auth/me, the current authenticated user + permission grants + granted modules. */
final class MeAction
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ModuleAccess $modules,
        private readonly \App\Service\PlatformSettings $settings,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        return Json::write($response, [
            'user' => $user->toOwnArray(),
            'permissions' => $this->permissions->grantsFor($user->getRole()),
            // Custom roles only: module => granted actions, enforced server-side
            // (RequirePermissionMiddleware); the client hides menu entries without "view".
            'role_matrix' => $this->settings->bool('security.rbac_enforce') ? $this->permissions->matrixFor($user->getRole()) : null,
            // Plan modules, plus "messaging" while the platform owner has direct messaging on.
            'modules' => array_merge(
                $this->modules->forUser($user),
                $this->settings->bool('feature_flags.messaging') ? ['messaging'] : []
            ),
            'all_modules' => SubscriptionPlan::MODULES,
            // How this user's school wants names, dates and classes presented
            // (School Settings); the web app applies these everywhere.
            'school' => $this->schoolPrefs($user),
        ]);
    }

    /** @return array<string,mixed>|null null for platform users, who belong to no school */
    private function schoolPrefs(User $user): ?array
    {
        $s = $user->getInstitution()?->getSettings();
        if ($user->getInstitution() === null) {
            return null;
        }
        $pick = static fn (string $section, string $key, string $default, array $allowed): string =>
            in_array($s[$section][$key] ?? null, $allowed, true) ? $s[$section][$key] : $default;

        return [
            'naming_convention' => $pick('academic', 'naming_convention', 'first_last', ['first_last', 'last_first']),
            'class_structure' => $pick('academic', 'class_structure', 'grade_based', ['grade_based', 'form_based', 'year_based']),
            'timezone' => $pick('localization', 'timezone', 'Africa/Lagos', ['Africa/Lagos', 'UTC', 'Europe/London', 'America/New_York']),
            'date_format' => $pick('localization', 'date_format', 'DD MMM YYYY', ['DD MMM YYYY', 'MM/DD/YYYY', 'DD/MM/YYYY']),
            'language' => $pick('localization', 'language', 'en', ['en', 'fr', 'ar']),
            'auto_save' => ($s['data']['auto_save'] ?? true) !== false,
        ];
    }
}
