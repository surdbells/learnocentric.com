<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\PasswordService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /backend/auth/password, change own password (verify current, set new). */
final class ChangePasswordAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordService $passwords,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['confirm_password'] ?? $body['new_password_confirmation'] ?? '');

        if ($current === '' || $next === '') {
            return Json::error($response, 'Current and new password are required.', 422);
        }
        if (!$this->passwords->verify($current, $user->getPasswordHash())) {
            return Json::error($response, 'Your current password is incorrect.', 422);
        }
        if (strlen($next) < 8) {
            return Json::error($response, 'New password must be at least 8 characters.', 422);
        }
        if ($next !== $confirm) {
            return Json::error($response, 'New password and confirmation do not match.', 422);
        }
        if ($this->passwords->verify($next, $user->getPasswordHash())) {
            return Json::error($response, 'New password must be different from the current one.', 422);
        }

        $user->setPasswordHash($this->passwords->hash($next));
        $this->em->flush();
        $this->audit->log('user.password.change', $user, 'User', (string) $user->getId());

        return Json::write($response, ['ok' => true, 'message' => 'Password updated.']);
    }
}
