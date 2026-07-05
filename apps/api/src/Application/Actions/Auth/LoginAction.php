<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LoginAction
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly JwtService $jwt,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            return Json::error($response, 'Email and password are required.', 422);
        }

        $user = $this->auth->attempt($email, $password);
        if ($user === null) {
            return Json::error($response, 'Incorrect email or password.', 401);
        }

        $token = $this->jwt->issueForUser($user);
        $this->audit->log('auth.login', $user, 'User', (string) $user->getId(), null, null, $this->ip($request));

        return Json::write($response, ['token' => $token, 'user' => $user->toArray()]);
    }

    private function ip(Request $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '') . ' ' . $request->getHeaderLine('User-Agent');
    }
}
