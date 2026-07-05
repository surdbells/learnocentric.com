<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\ModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Enforces plan module scoping: if a route belongs to a gateable feature module
 * (SubscriptionPlan::MODULES) and the caller's institution has not been granted
 * that module by its current subscription, the request is refused with 402.
 *
 * Runs after JwtAuthMiddleware so the authenticated User is available. Core
 * features (auth, dashboards, roster, curriculum authoring, billing, learn,
 * notifications, feedback) are never gated and simply fall through.
 */
class ModuleGateMiddleware implements MiddlewareInterface
{
    /**
     * Ordered path-prefix → module map. The first prefix that matches the
     * request path (relative to /backend) decides the gate. More specific
     * prefixes must come before broader ones.
     *
     * @var array<string, string>
     */
    private const MAP = [
        '/assessment/worksheet-submissions' => 'worksheets',
        '/assessment/worksheets' => 'worksheets',
        '/assessment/portfolio' => 'portfolio',
        '/assessment/questions' => 'assessments',
        '/assessment/assessments' => 'assessments',
        '/assessment/attempts' => 'assessments',
        '/assessment/gradebook' => 'assessments',
        '/live-classes' => 'live_classes',
        '/analytics' => 'analytics',
        '/school/interventions' => 'interventions',
        '/safeguarding' => 'safeguarding',
    ];

    public function __construct(private readonly ModuleAccess $modules)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $module = $this->moduleFor($request->getUri()->getPath());
        if ($module === null) {
            return $handler->handle($request);
        }

        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if (!$this->modules->grants($user, $module)) {
            return Json::error(
                new SlimResponse(),
                sprintf('The "%s" module is not included in your school\'s current plan.', $module),
                402,
            );
        }

        return $handler->handle($request);
    }

    private function moduleFor(string $path): ?string
    {
        // Normalise "/backend/assessment/worksheets" → "/assessment/worksheets".
        $path = preg_replace('#^/backend#', '', $path) ?? $path;
        foreach (self::MAP as $prefix => $module) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $module;
            }
        }
        return null;
    }
}
