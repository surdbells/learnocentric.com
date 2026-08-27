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

    /**
     * Tenant-ownership guard for mutation handlers.
     *
     * List queries are institution-scoped, but update/delete handlers that load a
     * record by primary key must also verify the record belongs to the caller's
     * institution, otherwise a scoped user (school_admin/teacher) can act on another
     * school's record by supplying its id (cross-tenant IDOR).
     *
     * Rules:
     *  - A platform actor (no institution, e.g. super admin) may act across tenants.
     *  - An institution-scoped caller may act only on records owned by that same
     *    institution. A record with no owner ($owner === null) is not actionable by a
     *    scoped caller.
     *
     * Callers should treat a false result as "not found" (404) rather than "forbidden"
     * so record existence is not leaked across tenants.
     */
    protected function canActWithin(Request $request, ?Institution $owner): bool
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $caller = $user?->getInstitution();

        if ($caller === null) {
            return true; // platform-level actor (super admin), governs across tenants
        }

        return $owner !== null && $owner->getId() === $caller->getId();
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

    /**
     * @param bool $includeSensitive Whether to expose sensitive contact fields
     *   (phone, date of birth). These are restricted to admin-level roles; plain
     *   teachers listing learners should not see them (spec §15 sensitive-data control).
     */
    protected function userRow(User $u, bool $includeSensitive = true): array
    {
        $row = [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'first_name' => $u->getFirstName(),
            'last_name' => $u->getLastName(),
            'is_active' => $u->getStatus() === 'active',
            'profile_image_url' => $u->getProfileImageUrl(),
        ];
        if ($includeSensitive) {
            $row['phone'] = $u->getPhone();
            $row['date_of_birth'] = $u->getDateOfBirth()?->format('Y-m-d');
        }

        return $row;
    }

    /** Roles allowed to see sensitive learner contact fields in listings. */
    protected function callerSeesSensitive(Request $request): bool
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        $role = $user?->getRole()?->getCode();

        return in_array($role, ['school_admin', 'tutor_admin', 'academic_lead', 'super_admin'], true);
    }
}
