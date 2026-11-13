<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\PermissionService;
use App\Service\PlatformSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;

/**
 * Table-driven RBAC for institution-defined (custom) roles (spec §6, §24).
 *
 * A custom role is built on a system base role (teacher, academic lead, school
 * admin): its holders get the base role's access, NARROWED by the role's
 * permission matrix. Every protected route maps to a permission module below,
 * and the HTTP method (or a sub-action such as /transition, /bulk-delete,
 * /export) maps to one of the matrix actions. A custom-role user without that
 * grant is refused 403, the change takes effect on their next request.
 *
 * System roles keep the per-action rules coded into each endpoint; their
 * matrix is the platform's published reference, not a gate. Routes outside the
 * map (own profile, notifications, messaging, dashboards, search, uploads) are
 * personal and never gated. The super-admin "Enforce RBAC" switch
 * (security.rbac_enforce) turns this off platform-wide.
 */
class RequirePermissionMiddleware implements MiddlewareInterface
{
    /**
     * Path prefix (after /backend) → permission module. First match wins, so
     * more specific prefixes come first.
     */
    private const MODULES = [
        '/school/roles' => 'role',
        '/school/students' => 'user',
        '/school/teachers' => 'user',
        '/school/learners' => 'user',
        '/school/staff' => 'user',
        '/school/enrollments' => 'user',
        '/school/guardians' => 'user',
        '/teacher/students' => 'user',
        '/auth/user-profile' => 'user',
        '/school/scheme-of-work' => 'curriculum_pack',
        '/school/interventions' => 'intervention',
        '/school/' => 'academic_setup',        // classes, subjects, terms, calendar, profile, settings, sign-up
        '/teacher/classes' => 'academic_setup',
        '/catalog/subjects' => 'academic_setup',
        '/safeguarding/' => 'safeguarding_case',
        '/curriculum/delivery-packs' => 'delivery_pack',
        '/curriculum/' => 'curriculum_pack',  // topics, review queue, curriculum map
        '/assessment/gradebook' => 'gradebook',
        '/assessment/worksheet-submissions' => 'worksheet',
        '/assessment/worksheets' => 'worksheet',
        '/assessment/submissions' => 'worksheet',
        '/assessment/portfolio' => 'portfolio',
        '/assessment/questions' => 'assessment',
        '/assessment/assessments' => 'assessment',
        '/assessment/feedback' => 'assessment',
        '/assessment/insights' => 'assessment',
        '/analytics/report-card' => 'gradebook',
        '/analytics/' => 'report',
        '/export/' => 'report',
        '/live-classes' => 'live_class',
        '/content/' => 'resource',
        '/billing/' => 'subscription',
        '/support/' => 'support_ticket',
        '/audit-logs' => 'audit_log',
        '/admin/institutions' => 'institution',
        '/admin/onboard' => 'institution',
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly PlatformSettings $settings,
    ) {
    }

    /**
     * Self-service endpoints that act only on the caller's own records (the
     * action itself checks ownership), so they are never matrix-gated.
     * [method regex, path regex, learner-only?]
     */
    private const SELF_SERVICE = [
        ['GET', '#^/assessment/worksheets/available$#', false],
        ['GET', '#^/assessment/worksheets/\d+/(solve|submission)$#', false],
        ['POST', '#^/assessment/worksheets/\d+/(save|submit)$#', false],
        ['GET', '#^/assessment/portfolio/(mine|tasks)$#', false],
        ['POST|PUT|DELETE', '#^/assessment/portfolio$#', false],
        ['GET', '#^/assessment/feedback/mine$#', false],
        ['POST', '#^/assessment/feedback/\d+/acknowledge$#', false],
        ['GET|PUT', '#^/content/my-resources/\d+/progress$#', false],
        // Anyone may raise a safeguarding concern, whatever their role's matrix says.
        ['POST', '#^/safeguarding/cases$#', false],
        ['GET', '#^/live-classes/(upcoming|board)$#', false],
        ['POST', '#^/live-classes/\d+/join$#', false],
        ['GET', '#^/analytics/(children|student/\d+|report-card/\d+)$#', true],
    ];

    public function process(Request $request, Handler $handler): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $role = $user?->getRole();
        // Public routes, and the platform owner, are governed by the endpoints' own rules.
        if ($role === null || $role->effectiveCode() === \App\Domain\Entity\Role::SUPER_ADMIN) {
            return $handler->handle($request);
        }
        $custom = !$role->isSystem();
        // The super-admin switch only controls custom-role narrowing; a custom
        // role then falls back to its base role. The system floor always applies.
        if ($custom && !$this->settings->bool('security.rbac_enforce')) {
            $role = $role->getBaseRole() ?? $role;
            $custom = !$role->isSystem();
        }

        $perm = $this->permissionFor($request, in_array($role->effectiveCode(), ['student', 'parent'], true));
        if ($perm === null) {
            return $handler->handle($request); // personal / self-service / un-mapped route
        }
        [$module, $action] = $perm;
        $allowed = $this->permissions->can($role, $module, $action)
            // A custom role can only narrow its base role, never widen it.
            && (!$custom || $role->getBaseRole() === null || $this->permissions->can($role->getBaseRole(), $module, $action));
        if (!$allowed) {
            return Json::error(new SlimResponse(), sprintf(
                'Your role (%s) does not include the "%s" permission for %s.',
                $role->getName(),
                $action,
                $this->permissions->label($module)
            ), 403);
        }

        return $handler->handle($request);
    }

    /** @return array{0:string,1:string}|null [module, action] for the request, or null if not gated */
    public function permissionFor(Request $request, bool $learner = false): ?array
    {
        // An explicit per-route tag wins over the map.
        $route = RouteContext::fromRequest($request)->getRoute();
        $tag = $route?->getArgument('perm');
        if ($tag !== null && str_contains($tag, ':')) {
            [$module, $action] = explode(':', $tag, 2);
            return [$module, $action];
        }

        $path = rtrim($request->getUri()->getPath(), '/');
        if (str_starts_with($path, '/backend')) {
            $path = substr($path, strlen('/backend'));
        }
        $method = strtoupper($request->getMethod());
        foreach (self::SELF_SERVICE as [$methods, $pattern, $learnerOnly]) {
            if ((!$learnerOnly || $learner) && in_array($method, explode('|', $methods), true) && preg_match($pattern, $path)) {
                return null;
            }
        }
        $module = self::moduleFor($path);
        if ($module === null) {
            return null;
        }

        return [$module, $this->actionFor($request, $path)];
    }

    public static function moduleFor(string $path): ?string
    {
        foreach (self::MODULES as $prefix => $module) {
            if (str_starts_with($path, $prefix)) {
                return $module;
            }
        }
        return null;
    }

    private function actionFor(Request $request, string $path): string
    {
        if (str_ends_with($path, '/bulk-delete')) {
            return 'delete';
        }
        if (str_ends_with($path, '/export') || str_starts_with($path, '/export/')) {
            return 'export';
        }
        if (str_ends_with($path, '/moderate')) {
            return 'approve';
        }
        // Working on an existing record through a sub-action is an edit.
        if (preg_match('#(/\d+/(password|review|grade|grade-questions|validate|questions|draw-from-bank|start|end)|/assign|/regenerate)$#', $path)
            && strtoupper($request->getMethod()) === 'POST') {
            return 'edit';
        }
        if (str_ends_with($path, '/transition')) {
            // Lifecycle moves: submitting for review is an edit; approving,
            // publishing, returning or restoring is an approval; takedown archives.
            $to = (string) (((array) $request->getParsedBody())['to'] ?? '');
            return match ($to) {
                'review', '' => 'edit',
                'archived' => 'archive',
                default => 'approve',
            };
        }

        return match (strtoupper($request->getMethod())) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };
    }
}
