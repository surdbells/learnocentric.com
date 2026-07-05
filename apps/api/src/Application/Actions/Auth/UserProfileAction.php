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
 * /backend/auth/user-profile/{id} — admin views/updates/deletes a user account.
 * Non-super admins are restricted to users within their own institution.
 */
final class UserProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
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
        $role = $actor->getRole()->getCode();
        if ($role === Role::SUPER_ADMIN) {
            return true;
        }
        if (in_array($role, [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD], true)) {
            // Same institution, and cannot manage platform/other admins.
            $sameInstitution = $actor->getInstitution() !== null
                && $target->getInstitution()?->getId() === $actor->getInstitution()->getId();
            $targetIsPrivileged = in_array($target->getRole()->getCode(), [Role::SUPER_ADMIN], true);

            return $sameInstitution && !$targetIsPrivileged;
        }

        return false;
    }
}
