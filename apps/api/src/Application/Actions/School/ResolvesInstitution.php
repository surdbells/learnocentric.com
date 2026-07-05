<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Resolves the institution scope for a request: the authenticated user's
 * institution, or (for super admins with no institution) an optional
 * ?institutionId query parameter.
 */
trait ResolvesInstitution
{
    protected function resolveInstitution(Request $request, EntityManagerInterface $em): ?Institution
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $institution = $user?->getInstitution();

        if ($institution === null) {
            $q = $request->getQueryParams();
            if (!empty($q['institutionId'])) {
                $institution = $em->find(Institution::class, (int) $q['institutionId']);
            }
        }

        return $institution;
    }

    /** @return User[] users of a role code, scoped to institution when set */
    protected function usersByRole(EntityManagerInterface $em, string $roleCode, ?Institution $institution): array
    {
        $qb = $em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join('u.role', 'r')
            ->where('r.code = :role')
            ->setParameter('role', $roleCode)
            ->orderBy('u.firstName', 'ASC');

        if ($institution !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $institution);
        }

        return $qb->getQuery()->getResult();
    }

    protected function userRow(User $u): array
    {
        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'first_name' => $u->getFirstName(),
            'last_name' => $u->getLastName(),
            'phone' => $u->getPhone(),
            'date_of_birth' => $u->getDateOfBirth()?->format('Y-m-d'),
            'is_active' => $u->getStatus() === 'active',
            'profile_image_url' => $u->getProfileImageUrl(),
        ];
    }
}
