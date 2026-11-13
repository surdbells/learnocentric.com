<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Small role guards for actions. Each returns a 403 response to send back, or
 * null when the caller may proceed:
 *
 *     if (($deny = Guard::superAdmin($request, $response)) !== null) { return $deny; }
 */
final class Guard
{
    public static function user(Request $request): ?User
    {
        $user = $request->getAttribute('user');
        return $user instanceof User ? $user : null;
    }

    public static function isSuperAdmin(Request $request): bool
    {
        return self::user($request)?->getRole()->effectiveCode() === Role::SUPER_ADMIN;
    }

    /** Platform-owner only. */
    public static function superAdmin(Request $request, Response $response, string $message = 'Only the platform owner can do this.'): ?Response
    {
        return self::isSuperAdmin($request) ? null : Json::error($response, $message, 403);
    }

    /**
     * Caller's effective role must be one of $codes. Custom roles resolve to
     * their base system role (see Role::effectiveCode()).
     *
     * @param string[] $codes
     */
    public static function roles(Request $request, Response $response, array $codes, string $message = 'You do not have access to this.'): ?Response
    {
        $user = self::user($request);
        return $user !== null && in_array($user->getRole()->effectiveCode(), $codes, true)
            ? null
            : Json::error($response, $message, 403);
    }
}
