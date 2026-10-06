<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /backend/admin/institutions/{id} — Super Admin removes an institution.
 *
 * Soft delete: the institution carries subjects, classes, users and more, so a
 * hard delete would break referential integrity. Instead we archive it (hidden
 * from the roster) and deactivate its users so they can no longer sign in.
 */
final class DeleteInstitutionAction
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
        $institution = $this->em->getRepository(Institution::class)->find((int) $args['id']);
        if ($institution === null) {
            return Json::error($response, 'Institution not found.', 404);
        }
        $before = $institution->toArray();

        $institution->setStatus('archived');

        // Lock out its users (defence in depth — the roster already hides them).
        $users = $this->em->getRepository(User::class)->findBy(['institution' => $institution]);
        foreach ($users as $user) {
            if (method_exists($user, 'setStatus')) {
                $user->setStatus('inactive');
            }
        }

        $this->em->flush();
        $this->audit->log('institution.archive', $actor, 'Institution', (string) $institution->getId(), $before, $institution->toArray());

        return Json::write($response, ['archived' => true, 'id' => $institution->getId()]);
    }
}
