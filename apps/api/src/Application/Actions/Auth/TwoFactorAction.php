<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Middleware\JwtAuthMiddleware;
use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\PasswordService;
use App\Service\PlatformSettings;
use App\Service\TotpService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * TOTP two-factor sign-in.
 *  GET  /auth/2fa               status
 *  POST /auth/2fa/setup         new pending secret + otpauth URI (for the QR)
 *  POST /auth/2fa/enable        {code} confirm enrolment → backup codes (shown once)
 *  POST /auth/2fa/disable       {password} switch off (refused while enforced for staff)
 *  POST /auth/2fa/backup-codes  {code} issue a fresh set of backup codes
 *  POST /auth/2fa/verify        (public) {challenge, code} second login step → session
 */
final class TwoFactorAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TotpService $totp,
        private readonly JwtService $jwt,
        private readonly AuthService $auth,
        private readonly PasswordService $passwords,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function status(Request $request, Response $response): Response
    {
        $user = $this->user($request);

        return Json::write($response, $this->state($user));
    }

    public function setup(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user->isTwoFactorEnabled()) {
            return Json::error($response, 'Two-factor authentication is already on. Turn it off first to re-enrol.', 409);
        }
        $secret = $this->totp->generateSecret();
        $user->setTotpSecret($this->totp->encrypt($secret));
        $user->setTotpEnabledAt(null);
        $this->em->flush();

        return Json::write($response, [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->uri($user, $secret),
        ]);
    }

    public function enable(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user->isTwoFactorEnabled()) {
            return Json::error($response, 'Two-factor authentication is already on.', 409);
        }
        if ($user->getTotpSecret() === null) {
            return Json::error($response, 'Start set-up first.', 422);
        }
        $step = $this->totp->verifySecret($this->totp->decrypt($user->getTotpSecret()), (string) (((array) $request->getParsedBody())['code'] ?? ''));
        if ($step === null) {
            return Json::error($response, 'That code didn\'t match. Check your phone\'s time is correct and try the current code.', 422);
        }
        $user->setTotpLastStep($step);
        $user->setTotpEnabledAt(new DateTimeImmutable());
        $user->clearTotpFailures();
        $codes = $this->totp->issueBackupCodes($user);
        $this->em->flush();
        $this->audit->log('auth.2fa_enabled', $user, 'User', (string) $user->getId());

        return Json::write($response, $this->state($user) + ['backup_codes' => $codes]);
    }

    public function disable(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if (JwtAuthMiddleware::twoFactorRequiredFor($user, $this->settings)) {
            return Json::error($response, 'Two-factor authentication is required for staff accounts on this platform.', 409);
        }
        if (!$this->passwords->verify((string) (((array) $request->getParsedBody())['password'] ?? ''), $user->getPasswordHash())) {
            return Json::error($response, 'Your password is incorrect.', 422);
        }
        $user->setTotpSecret(null);
        $user->setTotpEnabledAt(null);
        $user->setTotpBackupCodes(null);
        $user->setTotpLastStep(null);
        $user->clearTotpFailures();
        $this->em->flush();
        $this->audit->log('auth.2fa_disabled', $user, 'User', (string) $user->getId());

        return Json::write($response, $this->state($user));
    }

    public function backupCodes(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if (!$user->isTwoFactorEnabled()) {
            return Json::error($response, 'Two-factor authentication is off.', 422);
        }
        if (!$this->totp->verifyUser($user, (string) (((array) $request->getParsedBody())['code'] ?? ''), false)) {
            $this->em->flush();
            return Json::error($response, 'Enter the current 6-digit code from your authenticator app.', 422);
        }
        $codes = $this->totp->issueBackupCodes($user);
        $this->em->flush();
        $this->audit->log('auth.2fa_backup_codes', $user, 'User', (string) $user->getId());

        return Json::write($response, $this->state($user) + ['backup_codes' => $codes]);
    }

    /** Public: second step of sign-in. */
    public function verify(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $userId = $this->jwt->decodeTwoFactorChallenge((string) ($body['challenge'] ?? ''));
        } catch (Throwable) {
            return Json::error($response, 'Your sign-in session expired. Please sign in again.', 401);
        }
        $user = $this->auth->findById($userId);
        if ($user === null || $user->getStatus() !== 'active' || !$user->isTwoFactorEnabled()) {
            return Json::error($response, 'Your sign-in session expired. Please sign in again.', 401);
        }
        if ($this->totp->isLocked($user)) {
            return Json::error($response, 'Too many incorrect codes. Wait 15 minutes and try again.', 429);
        }
        $ok = $this->totp->verifyUser($user, (string) ($body['code'] ?? ''));
        if (!$ok) {
            $this->em->flush();
            $this->audit->log('auth.2fa_failed', $user, 'User', (string) $user->getId());
            return Json::error($response, 'That code is incorrect.', 401);
        }
        $user->markLoggedIn();
        $this->em->flush();
        $this->audit->log('auth.login', $user, 'User', (string) $user->getId(), null, ['two_factor' => true]);

        return Json::write($response, [
            'token' => $this->jwt->issueForUser($user, $this->settings->sessionSeconds()),
            'user' => $user->toArray(),
            'backup_codes_left' => count($user->getTotpBackupCodes()),
        ]);
    }

    // --- helpers ---

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }

    /** @return array<string,mixed> */
    private function state(User $user): array
    {
        return [
            'enabled' => $user->isTwoFactorEnabled(),
            'enabled_at' => $user->getTotpEnabledAt()?->format(DATE_ATOM),
            'backup_codes_left' => count($user->getTotpBackupCodes()),
            'required' => JwtAuthMiddleware::twoFactorRequiredFor($user, $this->settings),
        ];
    }
}
