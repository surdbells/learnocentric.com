<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\PermissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;

/**
 * Table-driven RBAC enforcement (spec §6, §24: "Are roles and permissions
 * table-driven or hardcoded?").
 *
 * Runs innermost, after JwtAuthMiddleware has attached the `user`. A route opts in
 * to enforcement by carrying a `perm` argument of the form "subject:action"
 * (e.g. "assessments:approve"), set via ->setArgument('perm', '...') in routes.php.
 *
 * When the RBAC_ENFORCE env flag is on, the caller's role must be granted that action
 * in the role_permissions table (via PermissionService) or the request is rejected 403.
 * When the flag is OFF (the default) this is a pass-through — so switching to
 * table-driven enforcement is a deliberate, reversible step that cannot lock anyone out
 * until the seeded permission matrix has been verified in staging. Untagged routes are
 * never affected.
 */
class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->enforcing()) {
            return $handler->handle($request);
        }

        $route = RouteContext::fromRequest($request)->getRoute();
        $perm = $route?->getArgument('perm');
        if ($perm === null || !str_contains($perm, ':')) {
            return $handler->handle($request); // untagged route — not enforced
        }
        [$code, $action] = explode(':', $perm, 2);

        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $role = $user?->getRole();
        if ($role === null || !$this->permissions->can($role, $code, $action)) {
            return Json::error(new SlimResponse(), 'You do not have permission to perform this action.', 403);
        }

        return $handler->handle($request);
    }

    /** Enforcement is opt-in via the RBAC_ENFORCE env flag; off by default. */
    private function enforcing(): bool
    {
        $flag = $_ENV['RBAC_ENFORCE'] ?? $_SERVER['RBAC_ENFORCE'] ?? getenv('RBAC_ENFORCE');

        return in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
    }
}
