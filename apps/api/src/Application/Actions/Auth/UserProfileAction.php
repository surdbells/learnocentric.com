<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * /backend/auth/user-profile/{id}, admin views/updates/deletes a user account.
 * Non-super admins are restricted to users within their own institution.
 */
final class UserProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly \App\Service\PasswordService $passwords,
        private readonly \App\Service\PlatformSettings $settings,
    ) {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $target = $this->em->getRepository(User::class)->find((int) ($args['id'] ?? 0));
        if ($target === null) {
            return Json::error($response, 'User not found.', 404);
        }
        if (!$this->canManage($actor, $target)) {
            return Json::error($response, 'You are not allowed to manage this user.', 403);
        }

        return match (strtoupper($request->getMethod())) {
            'PUT' => $this->update($request, $response, $actor, $target),
            'DELETE' => $this->delete($response, $actor, $target),
            default => Json::write($response, $target->toArray()),
        };
    }

    private function update(Request $request, Response $response, User $actor, User $target): Response
    {
        $before = $target->toArray();
        $body = (array) $request->getParsedBody();

        if (!empty($body['firstName'])) { $target->setFirstName((string) $body['firstName']); }
        if (!empty($body['lastName'])) { $target->setLastName((string) $body['lastName']); }
        if (!empty($body['email'])) { $target->setEmail((string) $body['email']); }
        if (array_key_exists('phone', $body)) { $target->setPhone($body['phone'] !== null && $body['phone'] !== '' ? (string) $body['phone'] : null); }
        if (array_key_exists('isActive', $body)) { $target->setStatus(filter_var($body['isActive'], FILTER_VALIDATE_BOOL) ? 'active' : 'suspended'); }
        if (!empty($body['dateOfBirth'])) {
            try { $target->setDateOfBirth(new DateTimeImmutable((string) $body['dateOfBirth'])); } catch (Throwable) {}
        }

        $this->em->flush();
        $this->audit->log('user.update', $actor, 'User', (string) $target->getId(), $before, $target->toArray());

        return Json::write($response, $target->toArray());
    }

    private function delete(Response $response, User $actor, User $target): Response
    {
        if ($target->getId() === $actor->getId()) {
            return Json::error($response, 'You cannot delete your own account.', 422);
        }
        $before = $target->toArray();
        $id = $target->getId();
        $this->em->remove($target);
        $this->em->flush();
        $this->audit->log('user.delete', $actor, 'User', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    private function canManage(User $actor, User $target): bool
    {
        $role = $actor->getRole()->effectiveCode();
        if ($role === Role::SUPER_ADMIN) {
            return true;
        }
        if (in_array($role, [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD], true)) {
            // Same institution, and cannot manage platform/other admins.
            $sameInstitution = $actor->getInstitution() !== null
                && $target->getInstitution()?->getId() === $actor->getInstitution()->getId();
            // Nobody here manages the platform owner, and an academic lead can't manage the school's admins.
            $privileged = $role === Role::ACADEMIC_LEAD
                ? [Role::SUPER_ADMIN, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN]
                : [Role::SUPER_ADMIN];
            $targetIsPrivileged = in_array($target->getRole()->effectiveCode(), $privileged, true);

            return $sameInstitution && !$targetIsPrivileged;
        }

        return false;
    }

    /** Higher number = more authority; you may only set passwords for accounts below you. */
    private const RANK = [
        Role::STUDENT => 1, Role::PARENT => 1, Role::TEACHER => 2, Role::ACADEMIC_LEAD => 3,
        Role::SCHOOL_ADMIN => 4, Role::TUTOR_ADMIN => 4, Role::SUPER_ADMIN => 5,
    ];

    /**
     * POST /auth/user-profile/{id}/password {password}: an administrator sets a
     * temporary password for someone who can't sign in (they change it after).
     */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $target = $this->em->getRepository(User::class)->find((int) ($args['id'] ?? 0));
        if ($target === null || !$this->canManage($actor, $target)) {
            return Json::error($response, 'User not found.', 404);
        }
        $mine = self::RANK[$actor->getRole()->effectiveCode()] ?? 0;
        $theirs = self::RANK[$target->getRole()->effectiveCode()] ?? 99;
        if ($theirs >= $mine) {
            return Json::error($response, 'You can only set passwords for accounts below your own role. They can use "Forgot password" instead.', 403);
        }
        $password = (string) (((array) $request->getParsedBody())['password'] ?? '');
        $min = max(6, (int) $this->settings->get('security.password_min_length', 8));
        if (strlen($password) < $min) {
            return Json::error($response, "Choose a temporary password of at least $min characters.", 422);
        }
        $target->setPasswordHash($this->passwords->hash($password));
        $target->clearTotpFailures();
        $this->em->flush();
        // The password itself is never written to the audit trail.
        $this->audit->log('user.password_set_by_admin', $actor, 'User', (string) $target->getId());

        return Json::write($response, ['ok' => true, 'message' => 'Temporary password set. Ask them to change it after signing in.']);
    }
}
