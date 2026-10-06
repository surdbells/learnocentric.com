<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/admin/institutions/{id} */
final class GetInstitutionAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $institution = $this->em->getRepository(Institution::class)->find((int) $args['id']);
        if ($institution === null) {
            return Json::error($response, 'Institution not found.', 404);
        }

        // Admins (school_admin / tutor_admin) for this institution, so the edit form can pre-fill.
        $adminUsers = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')
            ->andWhere('r.code IN (:codes)')
            ->setParameter('inst', $institution)
            ->setParameter('codes', ['school_admin', 'tutor_admin'])
            ->orderBy('u.id', 'ASC')
            ->getQuery()->getResult();

        $admins = array_map(static fn (User $u) => [
            'id' => $u->getId(),
            'first_name' => $u->getFirstName(),
            'last_name' => $u->getLastName(),
            'email' => $u->getEmail(),
        ], $adminUsers);

        $contact = $institution->getAdminContact() ?? [];
        $data = $institution->toArray() + [
            // Convenience fields the SPA edit form reads directly.
            'admins' => $admins,
            'email' => $admins[0]['email'] ?? ($contact['email'] ?? null),
            'phone' => $contact['phone'] ?? null,
            'primary_color' => ($institution->getBranding() ?? [])['primary_color'] ?? null,
            'is_active' => $institution->getStatus() === 'active',
        ];

        return Json::write($response, $data);
    }
}
