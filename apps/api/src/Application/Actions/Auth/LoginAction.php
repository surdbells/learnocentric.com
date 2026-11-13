<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Middleware\JwtAuthMiddleware;
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
        private readonly \App\Service\PlatformSettings $settings,
        private readonly \App\Service\AccountMailer $accountMail,
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
            // Log failed attempts (spec §15 audit trail), no actor, capture the email tried + IP/device.
            $this->audit->log('auth.login_failed', null, 'User', null, null, ['email' => $email], $this->ip($request));
            return Json::error($response, 'Incorrect email or password.', 401);
        }

        // Maintenance mode / parent portal switched off: say so instead of signing in.
        if (($block = $this->settings->blockFor($user)) !== null) {
            return Json::write($response, ['error' => $block[2], 'code' => $block[1]], $block[0]);
        }

        // Self-registered accounts must confirm their email first (only when we can actually email them).
        if (!$user->isEmailVerified()
            && $this->settings->bool('registration.require_email_verification')
            && $this->accountMail->canDeliver()) {
            return Json::write($response, [
                'error' => 'Confirm your email address to sign in. Check your inbox for the link we sent.',
                'code' => 'email_unverified',
            ], 403);
        }

        // Second factor: the password step only earns a short-lived challenge.
        if ($user->isTwoFactorEnabled()) {
            $this->audit->log('auth.login_password_ok', $user, 'User', (string) $user->getId(), null, null, $this->ip($request));
            return Json::write($response, [
                'two_factor_required' => true,
                'challenge' => $this->jwt->issueTwoFactorChallenge($user),
            ]);
        }

        $token = $this->jwt->issueForUser($user, $this->settings->sessionSeconds());
        $this->audit->log('auth.login', $user, 'User', (string) $user->getId(), null, null, $this->ip($request));

        return Json::write($response, [
            'token' => $token,
            'user' => $user->toArray(),
            // Platform requires 2FA for staff and this account hasn't enrolled yet.
            'two_factor_setup_required' => JwtAuthMiddleware::mustEnrolTwoFactor($user, $this->settings),
        ]);
    }

    private function ip(Request $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '') . ' ' . $request->getHeaderLine('User-Agent');
    }
}
