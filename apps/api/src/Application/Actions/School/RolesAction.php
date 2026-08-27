<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Permission;
use App\Domain\Entity\Role;
use App\Domain\Entity\RolePermission;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * School Roles & Permissions. School admins view the global system roles
 * (read-only, since they're shared across institutions) and fully manage their
 * own institution's custom roles: create, edit the permission matrix, delete,
 * and assign staff. Permission changes drive the same RolePermission RBAC the
 * server already enforces.
 */
final class RolesAction
{
    use ResolvesInstitution;

    private const ADMIN = ['school_admin', 'tutor_admin', 'super_admin'];
    private const ACTIONS = ['view', 'create', 'edit', 'approve', 'export', 'delete', 'archive'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /school/roles, roles visible to the admin + grants, counts and stats. */
    public function list(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('r')->from(Role::class, 'r');
        if ($institution !== null) {
            $qb->where('r.institution IS NULL OR r.institution = :inst')->setParameter('inst', $institution);
        } else {
            $qb->where('r.institution IS NULL');
        }
        $roles = $qb->getQuery()->getResult();

        $rows = [];
        $customCount = 0;
        foreach ($roles as $role) {
            /** @var Role $role */
            $grants = [];
            foreach ($this->em->getRepository(RolePermission::class)->findBy(['role' => $role]) as $rp) {
                $grants[$rp->getPermissionCode()] = $rp->toArray();
            }
            $usersAssigned = (int) $this->em->getRepository(User::class)->count(['role' => $role]);
            $editable = !$role->isSystem() && $institution !== null && $role->getInstitution()?->getId() === $institution->getId();
            if (!$role->isSystem()) {
                $customCount++;
            }
            $rows[] = $role->toArray() + [
                'users_assigned' => $usersAssigned,
                'editable' => $editable,
                // Permissions stay read-only for system roles, but staff can still be
                // assigned to school-scoped staff roles (e.g. Academic Lead), not just
                // custom roles. Platform, student and guardian roles are never assignable here.
                'assignable' => $editable || $this->isAssignableSystemRole($role),
                'grants' => $grants,
                'permission_level' => $this->levelFor($grants),
                'access_scope' => $role->getScope() === 'platform' ? 'Platform' : 'School',
            ];
        }
        usort($rows, static fn ($a, $b) => ($b['is_system'] <=> $a['is_system']) ?: strcmp($a['name'], $b['name']));

        return Json::write($response, [
            'data' => $rows,
            'permissions' => $this->modules(),
            'stats' => [
                'total_roles' => count($rows),
                'custom_roles' => $customCount,
                'permission_modules' => count($this->modules()),
                'elevated_users' => $this->elevatedUsers($institution),
            ],
        ]);
    }

    /** POST /school/roles, create a custom institution role {name, description?, scope?, grants}. */
    public function create(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Json::error($response, 'A role name is required.', 422);
        }
        $code = $this->uniqueCode($institution, $name);
        $role = new Role($code, $name, (string) ($body['scope'] ?? 'school'), false);
        $role->setInstitution($institution);
        $role->setDescription($this->str($body['description'] ?? null));
        $this->em->persist($role);
        $this->applyGrants($role, (array) ($body['grants'] ?? []));
        $this->em->flush();
        $this->audit->log('role.create', $request->getAttribute('user'), 'Role', (string) $role->getId(), null, ['name' => $name]);

        return Json::write($response, $role->toArray(), 201);
    }

    /** PUT /school/roles/{id}, edit a custom role's name/description/grants. */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $role = $this->em->getRepository(Role::class)->find((int) $args['id']);
        if (($err = $this->guardEditable($request, $role, $response)) !== null) {
            return $err;
        }
        /** @var Role $role */
        $body = (array) $request->getParsedBody();
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $role->setName(trim((string) $body['name']));
        }
        if (array_key_exists('description', $body)) {
            $role->setDescription($this->str($body['description']));
        }
        if (array_key_exists('grants', $body)) {
            // Replace the grant set wholesale.
            foreach ($this->em->getRepository(RolePermission::class)->findBy(['role' => $role]) as $rp) {
                $this->em->remove($rp);
            }
            $this->em->flush();
            $this->applyGrants($role, (array) $body['grants']);
        }
        $this->em->flush();
        $this->audit->log('role.update', $request->getAttribute('user'), 'Role', (string) $role->getId(), null, null);

        return Json::write($response, $role->toArray());
    }

    /** DELETE /school/roles?id=, delete a custom role (must have no users assigned). */
    public function delete(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $role = $this->em->getRepository(Role::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if (($err = $this->guardEditable($request, $role, $response)) !== null) {
            return $err;
        }
        /** @var Role $role */
        if ($this->em->getRepository(User::class)->count(['role' => $role]) > 0) {
            return Json::error($response, 'Reassign the users on this role before deleting it.', 409);
        }
        $id = $role->getId();
        foreach ($this->em->getRepository(RolePermission::class)->findBy(['role' => $role]) as $rp) {
            $this->em->remove($rp);
        }
        $this->em->remove($role);
        $this->em->flush();
        $this->audit->log('role.delete', $request->getAttribute('user'), 'Role', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** GET /school/roles/assignable-users, institution staff + their current role. */
    public function assignableUsers(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::write($response, ['data' => []]);
        }
        // Any institution staff member may be re-roled, everyone except learners and guardians.
        $users = $this->em->createQueryBuilder()->select('u', 'r')->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')->andWhere('r.code NOT IN (:excluded)')
            ->setParameter('inst', $institution)->setParameter('excluded', ['student', 'parent'])
            ->orderBy('u.firstName', 'ASC')->getQuery()->getResult();

        return Json::write($response, ['data' => array_map(static fn (User $u) => [
            'id' => $u->getId(),
            'name' => trim($u->getFirstName() . ' ' . $u->getLastName()),
            'email' => $u->getEmail(),
            'role_id' => $u->getRole()->getId(),
            'role' => $u->getRole()->getName(),
        ], $users)]);
    }

    /** POST /school/roles/assign, {user_id, role_id} set a staff member's role. */
    public function assign(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $body = (array) $request->getParsedBody();
        $user = $this->em->getRepository(User::class)->find((int) ($body['user_id'] ?? 0));
        $role = $this->em->getRepository(Role::class)->find((int) ($body['role_id'] ?? 0));
        if ($user === null || $role === null || $institution === null || $user->getInstitution()?->getId() !== $institution->getId()) {
            return Json::error($response, 'User or role not found in your institution.', 404);
        }
        // Custom roles must belong to this institution…
        if (!$role->isSystem() && $role->getInstitution()?->getId() !== $institution->getId()) {
            return Json::error($response, 'That role is not available to your institution.', 403);
        }
        // …and system roles are only assignable here if they are school-scoped staff roles.
        // This blocks a school admin from elevating anyone to a platform role (e.g. Super Admin).
        if ($role->isSystem() && !$this->isAssignableSystemRole($role)) {
            return Json::error($response, 'That role cannot be assigned from here.', 403);
        }
        $user->setRole($role);
        $this->em->flush();
        $this->audit->log('role.assign', $request->getAttribute('user'), 'User', (string) $user->getId(), null, ['role' => $role->getCode()]);

        return Json::write($response, ['user_id' => $user->getId(), 'role_id' => $role->getId(), 'role' => $role->getName()]);
    }

    // --- helpers ---

    /** Learner / guardian roles are never a school-staff assignment target. */
    private const NON_STAFF_ROLES = ['student', 'parent'];

    /**
     * A system role a school admin may assign staff to: school-scoped (never a
     * platform role such as Super Admin) and not a learner/guardian role. This
     * lets non-teaching academic staff roles (e.g. Academic Lead) receive members
     * even though their permission set stays platform-managed and read-only.
     */
    private function isAssignableSystemRole(Role $role): bool
    {
        return $role->isSystem()
            && $role->getScope() === 'school'
            && !in_array($role->getCode(), self::NON_STAFF_ROLES, true);
    }

    /** @return array<int,array{code:string,label:string}> */
    private function modules(): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Permission::class)->findBy([], ['code' => 'ASC']) as $p) {
            /** @var Permission $p */
            $rows[] = ['code' => $p->getCode(), 'label' => $p->getDescription() ?? $p->getCode()];
        }
        return $rows;
    }

    private function applyGrants(Role $role, array $grants): void
    {
        foreach ($grants as $code => $actions) {
            if (!is_array($actions)) {
                continue;
            }
            $flags = [];
            foreach (self::ACTIONS as $a) {
                $flags[$a] = (bool) ($actions[$a] ?? $actions['can_' . $a] ?? false);
            }
            if (!in_array(true, $flags, true)) {
                continue; // skip modules with no granted action
            }
            $this->em->persist(new RolePermission($role, (string) $code, $flags, $role->getScope()));
        }
        $this->em->flush();
    }

    private function levelFor(array $grants): string
    {
        $view = $create = $edit = $manage = 0;
        foreach ($grants as $g) {
            $view += $g['can_view'] ? 1 : 0;
            $create += $g['can_create'] ? 1 : 0;
            $edit += $g['can_edit'] ? 1 : 0;
            $manage += ($g['can_delete'] || $g['can_approve'] || $g['can_archive']) ? 1 : 0;
        }
        if ($view === 0) {
            return 'No Access';
        }
        if ($manage >= 5 && $create >= 5) {
            return 'Full Access';
        }
        if ($create + $edit >= 6) {
            return 'High Access';
        }
        if ($create + $edit >= 2) {
            return 'Moderate Access';
        }
        if ($create + $edit + $manage === 0) {
            return 'Read Only';
        }
        return 'Limited Access';
    }

    private function elevatedUsers(?Institution $institution): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code IN (:elev)')->setParameter('elev', ['school_admin', 'tutor_admin', 'academic_lead']);
        if ($institution !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $institution);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function uniqueCode(Institution $institution, string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?: 'role';
        $base = 'c' . $institution->getId() . '_' . trim($slug, '_');
        $code = $base;
        $n = 1;
        while ($this->em->getRepository(Role::class)->findOneBy(['code' => $code]) !== null) {
            $code = $base . '_' . (++$n);
        }
        return substr($code, 0, 50);
    }

    private function guardEditable(Request $request, ?Role $role, Response $response): ?Response
    {
        $institution = $this->resolveInstitution($request, $this->em);
        if ($role === null) {
            return Json::error($response, 'Role not found.', 404);
        }
        if ($role->isSystem()) {
            return Json::error($response, 'System roles are managed by the platform and cannot be edited here.', 403);
        }
        if ($institution === null || $role->getInstitution()?->getId() !== $institution->getId()) {
            return Json::error($response, 'That role is not available to your institution.', 404);
        }
        return null;
    }

    private function adminGuard(Request $request, Response $response): ?Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), self::ADMIN, true)) {
            return Json::error($response, 'Only administrators can manage roles.', 403);
        }
        return null;
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
