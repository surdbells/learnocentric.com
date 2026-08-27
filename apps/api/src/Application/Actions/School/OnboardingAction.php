<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Role;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Sectioned learner/staff onboarding (design: Add Learner / Add Staff). Creates
 * the account (reusing AuthService for hashing + email uniqueness), sets the
 * modelled fields (gender, DOB, admission number, profile photo), captures the
 * guardian / support / consent sections as an onboarding JSON blob, and wires
 * the academic placement (class enrolment for learners; class+subject
 * assignment for staff).
 */
final class OnboardingAction
{
    use ResolvesInstitution;

    private const ADMIN = ['school_admin', 'tutor_admin', 'academic_lead', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
    ) {
    }

    /** POST /school/learners, create a learner with placement + guardian/support/consent. */
    public function createLearner(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $body = (array) $request->getParsedBody();

        [$user, $err] = $this->createAccount($body, Role::STUDENT, $institution, $response);
        if ($err !== null) {
            return $err;
        }

        $user->setGender($this->str($body['gender'] ?? null));
        $user->setAdmissionNumber($this->str($body['admission_number'] ?? null));
        $this->applyDob($user, $body['date_of_birth'] ?? null);
        if (($photo = $this->str($body['profile_image_url'] ?? null)) !== null) {
            $user->setProfileImageUrl($photo);
        }
        $user->setOnboarding([
            'placement' => [
                'class_level' => $this->str($body['class_level'] ?? null),
                'arm' => $this->str($body['arm'] ?? null),
                'house' => $this->str($body['house'] ?? null),
                'previous_school' => $this->str($body['previous_school'] ?? null),
                'admission_date' => $this->str($body['admission_date'] ?? null),
                'enrollment_status' => $this->str($body['enrollment_status'] ?? 'pending'),
            ],
            'guardian' => $this->section($body['guardian'] ?? null, ['name', 'relationship', 'phone', 'email', 'whatsapp', 'address', 'emergency', 'has_account']),
            'support' => $this->section($body['support'] ?? null, ['medical', 'special_needs', 'transport', 'remarks']),
            'consent' => $this->section($body['consent'] ?? null, ['parent', 'media', 'data_privacy', 'comms_preference']),
        ]);

        // Academic placement: enrol into a class if given.
        $class = !empty($body['class_id']) ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null;
        if ($class !== null && $this->canActWithin($request, $class->getInstitution())) {
            $this->em->persist(new Enrollment($user, $class));
        }

        $this->em->flush();
        $this->audit->log('learner.onboard', $request->getAttribute('user'), 'User', (string) $user->getId(), null, ['class_id' => $class?->getId()]);

        return Json::write($response, ['user' => $user->toArray(), 'class_id' => $class?->getId()], 201);
    }

    /** POST /school/staff, create a staff member with role + optional class/subject assignment. */
    public function createStaff(Request $request, Response $response): Response
    {
        if (($g = $this->adminGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $body = (array) $request->getParsedBody();
        $role = ($body['role'] ?? 'teacher') === 'academic_lead' ? Role::ACADEMIC_LEAD : Role::TEACHER;

        [$user, $err] = $this->createAccount($body, $role, $institution, $response);
        if ($err !== null) {
            return $err;
        }

        $user->setGender($this->str($body['gender'] ?? null));
        if (($photo = $this->str($body['profile_image_url'] ?? null)) !== null) {
            $user->setProfileImageUrl($photo);
        }
        $user->setOnboarding([
            'employment' => [
                'staff_id' => $this->str($body['staff_id'] ?? null),
                'department' => $this->str($body['department'] ?? null),
                'employment_type' => $this->str($body['employment_type'] ?? null),
                'qualification' => $this->str($body['qualification'] ?? null),
                'start_date' => $this->str($body['start_date'] ?? null),
            ],
            'contact' => $this->section($body['contact'] ?? null, ['address', 'emergency']),
            'consent' => $this->section($body['consent'] ?? null, ['data_privacy', 'code_of_conduct', 'safeguarding_trained']),
        ]);

        // Optional teaching assignment (class + subject).
        $class = !empty($body['class_id']) ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null;
        $subject = !empty($body['subject_id']) ? $this->em->getRepository(Subject::class)->find((int) $body['subject_id']) : null;
        if ($class !== null && $subject !== null && $this->canActWithin($request, $class->getInstitution())) {
            $this->em->persist(new TeacherAssignment($user, $class, $subject));
        }

        $this->em->flush();
        $this->audit->log('staff.onboard', $request->getAttribute('user'), 'User', (string) $user->getId(), null, ['role' => $role]);

        return Json::write($response, ['user' => $user->toArray()], 201);
    }

    // --- helpers ---

    /** @return array{0: User|null, 1: Response|null} */
    private function createAccount(array $body, string $role, ?object $institution, Response $response): array
    {
        $email = trim((string) ($body['email'] ?? ''));
        $first = trim((string) ($body['firstName'] ?? $body['first_name'] ?? ''));
        $last = trim((string) ($body['lastName'] ?? $body['last_name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $first === '' || $last === '') {
            return [null, Json::error($response, 'First name, last name and email are required.', 422)];
        }
        if (strlen($password) < 6) {
            return [null, Json::error($response, 'A temporary password of at least 6 characters is required.', 422)];
        }
        try {
            $user = $this->auth->register([
                'email' => $email,
                'password' => $password,
                'firstName' => $first,
                'lastName' => $last,
                'role' => $role,
                'institutionId' => $institution?->getId(),
                'phone' => $body['phone'] ?? null,
            ]);
        } catch (Throwable $e) {
            return [null, Json::error($response, $e->getMessage(), 409)];
        }
        return [$user, null];
    }

    private function applyDob(User $user, mixed $value): void
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                $user->setDateOfBirth(new DateTimeImmutable($value));
            } catch (Throwable) {
                // ignore an unparseable date
            }
        }
    }

    /** Keep only the expected keys from a section object. @return array<string,mixed> */
    private function section(mixed $data, array $keys): array
    {
        $data = is_array($data) ? $data : [];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $data[$k] ?? null;
        }
        return $out;
    }

    private function str(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    private function adminGuard(Request $request, Response $response): ?Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), self::ADMIN, true)) {
            return Json::error($response, 'Only administrators can onboard learners and staff.', 403);
        }
        return null;
    }
}
