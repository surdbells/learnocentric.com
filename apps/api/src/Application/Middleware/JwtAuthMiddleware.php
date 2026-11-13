<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Support\Json;
use App\Service\AuthService;
use App\Service\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Throwable;

/**
 * Validates the Bearer JWT and attaches the authenticated User (and claims)
 * as request attributes: `user` and `jwt`.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    /** Routes a staff member may use before enrolling in required 2FA. */
    private const SETUP_ALLOWED = ['/backend/auth/me', '/backend/auth/2fa', '/backend/auth/2fa/setup', '/backend/auth/2fa/enable', '/backend/notifications'];

    public function __construct(
        private readonly JwtService $jwt,
        private readonly AuthService $auth,
        private readonly \App\Service\PlatformSettings $settings,
    ) {
    }

    /** Staff must enrol in 2FA when the platform enforces it (learners and parents are exempt). */
    public static function mustEnrolTwoFactor(\App\Domain\Entity\User $user, \App\Service\PlatformSettings $settings): bool
    {
        return !$user->isTwoFactorEnabled() && self::twoFactorRequiredFor($user, $settings);
    }

    /** Is 2FA mandatory for this user's role (whether or not they have it yet)? */
    public static function twoFactorRequiredFor(\App\Domain\Entity\User $user, \App\Service\PlatformSettings $settings): bool
    {
        return !in_array($user->getRole()->effectiveCode(), ['student', 'parent'], true)
            && $settings->bool('security.enforce_2fa');
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized('Missing or malformed Authorization header.');
        }

        try {
            $claims = $this->jwt->decode($m[1]);
        } catch (Throwable) {
            return $this->unauthorized('Invalid or expired token.');
        }

        // Purpose-bound tokens (e.g. the 2FA challenge) are never sessions.
        if (isset($claims['purpose'])) {
            return $this->unauthorized('Invalid or expired token.');
        }

        $userId = isset($claims['userId']) ? (int) $claims['userId'] : 0;
        $user = $userId > 0 ? $this->auth->findById($userId) : null;
        if ($user === null || $user->getStatus() !== 'active') {
            return $this->unauthorized('User not found or inactive.');
        }

        if (self::mustEnrolTwoFactor($user, $this->settings)
            && !in_array(rtrim($request->getUri()->getPath(), '/'), self::SETUP_ALLOWED, true)) {
            return Json::write(new SlimResponse(), [
                'error' => 'Set up two-factor authentication to continue.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        $request = $request->withAttribute('user', $user)->withAttribute('jwt', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        return Json::error(new SlimResponse(), $message, 401);
    }
}
