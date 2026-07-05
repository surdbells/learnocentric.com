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
    public function __construct(
        private readonly JwtService $jwt,
        private readonly AuthService $auth,
    ) {
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

        $userId = isset($claims['userId']) ? (int) $claims['userId'] : 0;
        $user = $userId > 0 ? $this->auth->findById($userId) : null;
        if ($user === null || $user->getStatus() !== 'active') {
            return $this->unauthorized('User not found or inactive.');
        }

        $request = $request->withAttribute('user', $user)->withAttribute('jwt', $claims);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        return Json::error(new SlimResponse(), $message, 401);
    }
}
