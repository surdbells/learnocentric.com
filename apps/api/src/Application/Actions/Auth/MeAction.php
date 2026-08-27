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
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        return Json::write($response, [
            'user' => $user->toArray(),
            'permissions' => $this->permissions->grantsFor($user->getRole()),
            'modules' => $this->modules->forUser($user),
            'all_modules' => SubscriptionPlan::MODULES,
        ]);
    }
}
