<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use App\Service\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * POST /backend/auth/register
 * - Called by an admin (Bearer token present): creates student/teacher/parent/lead
 *   scoped to the admin's institution (super admin may target any institution).
 * - Called publicly: restricted to self-registerable roles.
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

        $actor = $this->actorFromRequest($request);
        $requestedRole = (string) ($body['role'] ?? Role::STUDENT);
        $adminContext = $actor !== null && in_array($actor->getRole()->getCode(), self::ADMIN_ROLES, true);

        if ($adminContext) {
            $role = in_array($requestedRole, self::ADMIN_CREATABLE, true) ? $requestedRole : Role::STUDENT;
            // Non-super admins can only create within their own institution.
            $institutionId = $actor->getRole()->getCode() === Role::SUPER_ADMIN
                ? ($body['institutionId'] ?? null)
                : $actor->getInstitution()?->getId();
        } else {
            $role = in_array($requestedRole, self::SELF_REGISTERABLE, true) ? $requestedRole : Role::STUDENT;
            $institutionId = $body['institutionId'] ?? null;
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
            ]);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 409);
        }

        $this->audit->log('user.create', $actor ?? $user, 'User', (string) $user->getId(), null, $user->toArray());

        // Admin-created accounts don't return a token; self-registration does.
        if ($adminContext) {
            return Json::write($response, ['user' => $user->toArray()], 201);
        }

        return Json::write($response, ['token' => $this->jwt->issueForUser($user), 'user' => $user->toArray()], 201);
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
