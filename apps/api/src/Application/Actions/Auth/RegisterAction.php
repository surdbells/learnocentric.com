<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use App\Service\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * POST /backend/auth/register
 * - Called by an admin (Bearer token present): creates student/teacher/parent/lead
 *   scoped to the admin's institution (super admin may target any institution).
 * - Called publicly: self sign-up as a learner or parent, only with a valid school
 *   join code, and only when the platform allows self sign-up and that school has
 *   opened it. A raw institution id is never accepted from the public.
 *
 * GET /backend/auth/join/{code}: public lookup so the sign-up page can show which
 * school a join code belongs to before the person registers.
 */
final class RegisterAction
{
    private const ADMIN_ROLES = [Role::SUPER_ADMIN, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD];
    private const ADMIN_CREATABLE = [Role::STUDENT, Role::TEACHER, Role::PARENT, Role::ACADEMIC_LEAD];
    private const SELF_REGISTERABLE = [Role::STUDENT, Role::PARENT];

    public function __construct(
        private readonly AuthService $auth,
        private readonly JwtService $jwt,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
        private readonly PlatformSettings $settings,
        private readonly \App\Service\AccountMailer $accountMail,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        foreach (['email', 'password', 'firstName', 'lastName'] as $required) {
            if (empty($body[$required])) {
                return Json::error($response, "Field '{$required}' is required.", 422);
            }
        }
        $minLength = (int) $this->settings->get('security.password_min_length', 8);
        if (mb_strlen((string) $body['password']) < $minLength) {
            return Json::error($response, "Password must be at least {$minLength} characters.", 422);
        }

        $actor = $this->actorFromRequest($request);
        $requestedRole = (string) ($body['role'] ?? Role::STUDENT);
        $adminContext = $actor !== null && in_array($actor->getRole()->effectiveCode(), self::ADMIN_ROLES, true);

        if ($adminContext) {
            $role = in_array($requestedRole, self::ADMIN_CREATABLE, true) ? $requestedRole : Role::STUDENT;
            // Non-super admins can only create within their own institution.
            $institutionId = $actor->getRole()->effectiveCode() === Role::SUPER_ADMIN
                ? ($body['institutionId'] ?? null)
                : $actor->getInstitution()?->getId();
        } else {
            if (!$this->settings->bool('registration.allow_self_signup')) {
                return Json::error($response, 'Self sign-up is not available. Ask your school to create your account.', 403);
            }
            $institution = $this->institutionForCode((string) ($body['joinCode'] ?? ''));
            if ($institution === null) {
                return Json::error($response, 'That join code is not valid. Check it with your school.', 422);
            }
            $role = in_array($requestedRole, self::SELF_REGISTERABLE, true) ? $requestedRole : Role::STUDENT;
            $institutionId = $institution->getId();
        }

        try {
            $user = $this->auth->register([
                'email' => (string) $body['email'],
                'password' => (string) $body['password'],
                'firstName' => (string) $body['firstName'],
                'lastName' => (string) $body['lastName'],
                'role' => $role,
                'institutionId' => $institutionId,
                'phone' => $body['phone'] ?? null,
                // An admin vouches for accounts they create; self sign-up must prove the email.
                'verified' => $adminContext,
            ]);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 409);
        }

        $this->audit->log('user.create', $actor ?? $user, 'User', (string) $user->getId(), null, $user->toArray());

        // Admin-created accounts don't return a token; self-registration does.
        if ($adminContext) {
            return Json::write($response, ['user' => $user->toArray()], 201);
        }

        // When the platform requires it (and mail works), the account stays
        // signed out until the emailed link is opened.
        if ($this->settings->bool('registration.require_email_verification') && $this->accountMail->canDeliver()) {
            $this->accountMail->sendEmailVerification($user);

            return Json::write($response, [
                'verification_required' => true,
                'email' => $user->getEmail(),
                'message' => 'We\'ve sent a confirmation link to ' . $user->getEmail() . '. Open it to finish creating your account.',
            ], 201);
        }

        return Json::write($response, ['token' => $this->jwt->issueForUser($user), 'user' => $user->toArray()], 201);
    }

    /** GET /auth/join/{code}: which school a join code opens, or 404. */
    public function lookup(Request $request, Response $response, array $args): Response
    {
        if (!$this->settings->bool('registration.allow_self_signup')) {
            return Json::error($response, 'Self sign-up is not available.', 403);
        }
        $institution = $this->institutionForCode((string) ($args['code'] ?? ''));
        if ($institution === null) {
            return Json::error($response, 'That join code is not valid.', 404);
        }
        return Json::write($response, ['name' => $institution->getName(), 'type' => $institution->getType()]);
    }

    /** An active school that has opened self sign-up, by its join code. */
    private function institutionForCode(string $code): ?Institution
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        /** @var Institution|null $institution */
        $institution = $this->em->getRepository(Institution::class)->findOneBy(['joinCode' => $code]);
        return $institution !== null && $institution->isSelfSignup() && $institution->getStatus() === 'active'
            ? $institution
            : null;
    }

    private function actorFromRequest(Request $request): ?User
    {
        if (!preg_match('/^Bearer\s+(.+)$/i', $request->getHeaderLine('Authorization'), $m)) {
            return null;
        }
        try {
            $claims = $this->jwt->decode($m[1]);
        } catch (Throwable) {
            return null;
        }

        return isset($claims['userId']) ? $this->auth->findById((int) $claims['userId']) : null;
    }
}
