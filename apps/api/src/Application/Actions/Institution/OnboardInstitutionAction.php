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

        // Accept both the SPA's camelCase payload and snake_case.
        $name = trim((string) ($body['name'] ?? $body['institutionName'] ?? $body['school_name'] ?? ''));
        if ($name === '') {
            return Json::error($response, "Field 'name' is required.", 422);
        }

        $email = trim((string) ($body['admin_email'] ?? $body['adminEmail'] ?? ''));
        $password = (string) ($body['admin_password'] ?? $body['adminPassword'] ?? '');
        $firstName = trim((string) ($body['admin_first_name'] ?? $body['adminFirstName'] ?? '')) ?: 'School';
        $lastName = trim((string) ($body['admin_last_name'] ?? $body['adminLastName'] ?? '')) ?: 'Admin';
        $phone = trim((string) ($body['phone'] ?? ''));
        $primaryColor = trim((string) ($body['primaryColor'] ?? $body['primary_color'] ?? ''));

        $institution = new Institution($name);
        $institution->setType(InstitutionFields::normalizeType($body['type'] ?? $body['institutionType'] ?? 'school'));
        $institution->setAddress(($body['address'] ?? '') !== '' ? (string) $body['address'] : null);
        $institution->setLogoUrl($body['logo_url'] ?? $body['logoUrl'] ?? null);
        if ($primaryColor !== '') {
            $institution->setBranding(['primary_color' => $primaryColor]);
        }
        $institution->setAdminContact(InstitutionFields::adminContact($email, $phone, $firstName, $lastName, $body['admin_contact'] ?? null));
        if (($pkg = InstitutionFields::firstPackageId($body)) !== null) {
            $institution->setAssignedPackageId($pkg);
        }
        $this->em->persist($institution);
        $this->em->flush();

        $adminUser = null;
        // Optionally create the first school admin.
        if ($email !== '' && $password !== '') {
            try {
                $adminUser = $this->auth->register([
                    'email' => $email,
                    'password' => $password,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
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
