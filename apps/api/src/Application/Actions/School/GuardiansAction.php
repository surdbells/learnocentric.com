<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\GuardianLink;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Link parents/guardians to learners, so the parent portal (children,
 * progress reports, safeguarding) has someone to show.
 *  GET    /school/guardians?student_id=   a learner's linked guardians
 *  GET    /school/guardians/parents       parent accounts in the school (to pick from)
 *  POST   /school/guardians               {student_id, relationship?, guardian_id}
 *                                         or {student_id, relationship?, email, firstName, lastName, phone?, password}
 *  DELETE /school/guardians?id=           unlink (the parent account is kept)
 */
final class GuardiansAction
{
    use ResolvesInstitution;

    private const ADMIN = [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD, Role::SUPER_ADMIN];
    private const RELATIONSHIPS = ['parent', 'mother', 'father', 'guardian', 'sibling', 'relative', 'other'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly PlatformSettings $settings,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $student = $this->student($request, (int) ($request->getQueryParams()['student_id'] ?? 0));
        if ($student === null) {
            return Json::error($response, 'Learner not found.', 404);
        }
        $links = $this->em->getRepository(GuardianLink::class)->findBy(['student' => $student], ['id' => 'ASC']);

        return Json::write($response, [
            'data' => array_map(fn (GuardianLink $l) => $this->row($l), $links),
            // What the school typed at admission, to pre-fill "add a guardian".
            'recorded' => $student->getOnboarding()['guardian'] ?? null,
        ]);
    }

    public function parents(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::write($response, ['data' => []]);
        }
        $rows = $this->usersByRole($this->em, Role::PARENT, $institution);

        return Json::write($response, ['data' => array_map(static fn (User $u) => [
            'id' => $u->getId(),
            'name' => trim($u->getFirstName() . ' ' . $u->getLastName()),
            'email' => $u->getEmail(),
        ], $rows)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $body = (array) $request->getParsedBody();
        $student = $this->student($request, (int) ($body['student_id'] ?? 0));
        if ($student === null) {
            return Json::error($response, 'Learner not found.', 404);
        }
        $institution = $student->getInstitution();
        $relationship = strtolower(trim((string) ($body['relationship'] ?? 'parent')));
        if (!in_array($relationship, self::RELATIONSHIPS, true)) {
            $relationship = 'parent';
        }

        $created = false;
        if (!empty($body['guardian_id'])) {
            $guardian = $this->em->getRepository(User::class)->find((int) $body['guardian_id']);
        } else {
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'Enter the guardian\'s email address.', 422);
            }
            $guardian = $this->auth->findByEmail($email);
            if ($guardian === null) {
                $first = trim((string) ($body['firstName'] ?? $body['first_name'] ?? ''));
                $last = trim((string) ($body['lastName'] ?? $body['last_name'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                $min = max(6, (int) $this->settings->get('security.password_min_length', 8));
                if ($first === '' || $last === '') {
                    return Json::error($response, 'No parent account uses that email yet. Add their first and last name to create one.', 422);
                }
                if (strlen($password) < $min) {
                    return Json::error($response, "Set a temporary password of at least $min characters for the new parent account.", 422);
                }
                try {
                    $guardian = $this->auth->register([
                        'email' => $email, 'password' => $password, 'firstName' => $first, 'lastName' => $last,
                        'role' => Role::PARENT, 'institutionId' => $institution?->getId(), 'phone' => $body['phone'] ?? null,
                    ]);
                    $created = true;
                } catch (Throwable $e) {
                    return Json::error($response, $e->getMessage(), 409);
                }
            }
        }

        if ($guardian === null
            || $guardian->getRole()->effectiveCode() !== Role::PARENT
            || $guardian->getInstitution()?->getId() !== $institution?->getId()) {
            return Json::error($response, 'That account is not a parent account in this school.', 422);
        }
        if ($this->em->getRepository(GuardianLink::class)->findOneBy(['guardian' => $guardian, 'student' => $student]) !== null) {
            return Json::error($response, 'That guardian is already linked to this learner.', 409);
        }

        $link = new GuardianLink($guardian, $student, $relationship);
        $this->em->persist($link);
        $this->em->flush();
        $this->audit->log('guardian.link', $request->getAttribute('user'), 'GuardianLink', (string) $link->getId(), null, [
            'guardian_id' => $guardian->getId(), 'student_id' => $student->getId(), 'account_created' => $created,
        ]);

        return Json::write($response, $this->row($link) + ['account_created' => $created], 201);
    }

    public function delete(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $link = $this->em->getRepository(GuardianLink::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($link === null || !$this->canActWithin($request, $link->getStudent()->getInstitution())) {
            return Json::error($response, 'Link not found.', 404);
        }
        $id = $link->getId();
        $this->em->remove($link);
        $this->em->flush();
        $this->audit->log('guardian.unlink', $request->getAttribute('user'), 'GuardianLink', (string) $id);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /**
     * Link an existing parent account by email, if there is one in the same
     * school (used when a learner is admitted with a guardian email).
     */
    public static function autoLink(EntityManagerInterface $em, AuthService $auth, User $student, ?string $email, ?string $relationship): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return false;
        }
        $guardian = $auth->findByEmail($email);
        if ($guardian === null
            || $guardian->getRole()->effectiveCode() !== Role::PARENT
            || $guardian->getInstitution()?->getId() !== $student->getInstitution()?->getId()) {
            return false;
        }
        $rel = strtolower(trim((string) $relationship));
        $em->persist(new GuardianLink($guardian, $student, in_array($rel, self::RELATIONSHIPS, true) ? $rel : 'parent'));

        return true;
    }

    // --- helpers ---

    private function guard(Request $request, Response $response): ?Response
    {
        return Guard::roles($request, $response, self::ADMIN, 'Only administrators can manage guardians.');
    }

    private function student(Request $request, int $id): ?User
    {
        $student = $id > 0 ? $this->em->getRepository(User::class)->find($id) : null;
        if ($student === null
            || $student->getRole()->effectiveCode() !== Role::STUDENT
            || !$this->canActWithin($request, $student->getInstitution())) {
            return null;
        }
        return $student;
    }

    /** @return array<string,mixed> */
    private function row(GuardianLink $l): array
    {
        $g = $l->getGuardian();

        return [
            'id' => $l->getId(),
            'guardian_id' => $g->getId(),
            'name' => trim($g->getFirstName() . ' ' . $g->getLastName()),
            'email' => $g->getEmail(),
            'phone' => $g->getPhone(),
            'relationship' => $l->getRelationship(),
        ];
    }
}
