<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * POST /backend/admin/onboard, Super Admin onboards a school and (optionally)
 * its first School Admin user.
 */
final class OnboardInstitutionAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? $body['school_name'] ?? ''));
        if ($name === '') {
            return Json::error($response, "Field 'name' is required.", 422);
        }

        $institution = new Institution($name);
        $institution->setType((string) ($body['type'] ?? 'school'));
        $institution->setAddress($body['address'] ?? null);
        $institution->setLogoUrl($body['logo_url'] ?? $body['logoUrl'] ?? null);
        if (isset($body['admin_contact']) && is_array($body['admin_contact'])) {
            $institution->setAdminContact($body['admin_contact']);
        }
        $this->em->persist($institution);
        $this->em->flush();

        $adminUser = null;
        // Optionally create the first school admin.
        if (!empty($body['admin_email']) && !empty($body['admin_password'])) {
            try {
                $adminUser = $this->auth->register([
                    'email' => (string) $body['admin_email'],
                    'password' => (string) $body['admin_password'],
                    'firstName' => (string) ($body['admin_first_name'] ?? 'School'),
                    'lastName' => (string) ($body['admin_last_name'] ?? 'Admin'),
                    'role' => 'school_admin',
                    'institutionId' => $institution->getId(),
                ]);
            } catch (Throwable $e) {
                // Institution created but admin failed, report partial success.
                return Json::write($response, [
                    'institution' => $institution->toArray(),
                    'admin_error' => $e->getMessage(),
                ], 201);
            }
        }

        $this->audit->log('institution.onboard', $actor, 'Institution', (string) $institution->getId(), null, $institution->toArray());

        return Json::write($response, [
            'institution' => $institution->toArray(),
            'admin' => $adminUser?->toArray(),
        ], 201);
    }
}
