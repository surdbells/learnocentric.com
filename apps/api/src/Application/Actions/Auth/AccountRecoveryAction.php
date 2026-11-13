<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\AuthToken;
use App\Service\AccountMailer;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\PasswordService;
use App\Service\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public account-recovery endpoints (all authenticated by an emailed token,
 * never a session):
 *  POST /auth/password/forgot  {email}             email a reset link
 *  POST /auth/password/reset   {token, password}   set a new password
 *  POST /auth/email/verify     {token}             confirm the email address
 *  POST /auth/email/resend     {email}             email a fresh confirmation link
 *
 * "forgot" and "resend" always answer the same way, so they can't be used to
 * discover which emails have accounts.
 */
final class AccountRecoveryAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthService $auth,
        private readonly AccountMailer $mail,
        private readonly PasswordService $passwords,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function forgot(Request $request, Response $response): Response
    {
        $email = strtolower(trim((string) (((array) $request->getParsedBody())['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Json::error($response, 'Enter your email address.', 422);
        }
        $user = $this->auth->findByEmail($email);
        if ($user !== null && $user->getStatus() === 'active') {
            $sent = $this->mail->sendPasswordReset($user);
            $this->audit->log('auth.password_reset_requested', $user, 'User', (string) $user->getId(), null, ['emailed' => $sent]);
        }

        return Json::write($response, [
            'message' => 'If that email has an account, we\'ve sent a link to reset the password. It expires in 1 hour.',
            // Lets the page tell people to contact their school instead of waiting for an email that can't come.
            'email_available' => $this->mail->canDeliver(),
        ]);
    }

    public function reset(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $token = $this->mail->find((string) ($body['token'] ?? ''), AuthToken::PASSWORD_RESET);
        if ($token === null || $token->getUser()->getStatus() !== 'active') {
            return Json::error($response, 'This reset link is invalid or has expired. Request a new one.', 422);
        }
        $password = (string) ($body['password'] ?? '');
        $min = max(6, (int) $this->settings->get('security.password_min_length', 8));
        if (strlen($password) < $min) {
            return Json::error($response, "Choose a password of at least $min characters.", 422);
        }
        $user = $token->getUser();
        $user->setPasswordHash($this->passwords->hash($password));
        // Receiving the link proves the address, and unlocks a 2FA lockout from guessed codes.
        $user->markEmailVerified();
        $user->clearTotpFailures();
        $token->markUsed();
        $this->mail->revoke($user, AuthToken::PASSWORD_RESET);
        $this->em->flush();
        $this->audit->log('auth.password_reset', $user, 'User', (string) $user->getId());

        return Json::write($response, ['message' => 'Your password has been changed. You can sign in now.']);
    }

    public function verifyEmail(Request $request, Response $response): Response
    {
        $token = $this->mail->find((string) (((array) $request->getParsedBody())['token'] ?? ''), AuthToken::EMAIL_VERIFY);
        if ($token === null) {
            return Json::error($response, 'This confirmation link is invalid or has expired. Sign in to get a new one.', 422);
        }
        $user = $token->getUser();
        $user->markEmailVerified();
        $token->markUsed();
        $this->em->flush();
        $this->audit->log('auth.email_verified', $user, 'User', (string) $user->getId());

        return Json::write($response, ['message' => 'Your email is confirmed. You can sign in now.']);
    }

    public function resendVerification(Request $request, Response $response): Response
    {
        $email = strtolower(trim((string) (((array) $request->getParsedBody())['email'] ?? '')));
        $user = $email !== '' ? $this->auth->findByEmail($email) : null;
        if ($user !== null && $user->getStatus() === 'active' && !$user->isEmailVerified()) {
            $this->mail->sendEmailVerification($user);
        }

        return Json::write($response, ['message' => 'If that account still needs confirming, we\'ve sent a new link.']);
    }
}
