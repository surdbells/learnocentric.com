<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** PUT /backend/admin/institutions/{id} — Super Admin edits an institution. */
final class UpdateInstitutionAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $institution = $this->em->getRepository(Institution::class)->find((int) $args['id']);
        if ($institution === null) {
            return Json::error($response, 'Institution not found.', 404);
        }
        $before = $institution->toArray();
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? $body['institutionName'] ?? ''));
        if ($name !== '') {
            $institution->setName($name);
        }
        if (isset($body['type']) || isset($body['institutionType'])) {
            $institution->setType(InstitutionFields::normalizeType($body['type'] ?? $body['institutionType']));
        }
        if (array_key_exists('address', $body)) {
            $institution->setAddress(($body['address'] ?? '') !== '' ? (string) $body['address'] : null);
        }
        if (isset($body['logo_url']) || isset($body['logoUrl'])) {
            $institution->setLogoUrl($body['logo_url'] ?? $body['logoUrl'] ?? null);
        }

        $primaryColor = trim((string) ($body['primaryColor'] ?? $body['primary_color'] ?? ''));
        if ($primaryColor !== '') {
            $institution->setBranding(array_merge($institution->getBranding() ?? [], ['primary_color' => $primaryColor]));
        }

        $email = trim((string) ($body['admin_email'] ?? $body['adminEmail'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        $firstName = trim((string) ($body['admin_first_name'] ?? $body['adminFirstName'] ?? ''));
        $lastName = trim((string) ($body['admin_last_name'] ?? $body['adminLastName'] ?? ''));
        if ($email !== '' || $phone !== '' || $firstName !== '' || $lastName !== '') {
            $institution->setAdminContact(
                InstitutionFields::adminContact($email, $phone, $firstName, $lastName, $institution->getAdminContact())
            );
        }

        if (($pkg = InstitutionFields::firstPackageId($body)) !== null) {
            $institution->setAssignedPackageId($pkg);
        }

        if (array_key_exists('isActive', $body) || array_key_exists('is_active', $body)) {
            $active = $body['isActive'] ?? $body['is_active'];
            $isOn = is_bool($active) ? $active : in_array(strtolower((string) $active), ['1', 'true', 'yes', 'on'], true);
            $institution->setStatus($isOn ? 'active' : 'inactive');
        }

        // Keep the institution's first admin name in sync (no email/password changes here).
        if ($firstName !== '' || $lastName !== '') {
            $admin = $this->firstAdmin($institution);
            if ($admin !== null) {
                if ($firstName !== '') {
                    $admin->setFirstName($firstName);
                }
                if ($lastName !== '') {
                    $admin->setLastName($lastName);
                }
            }
        }

        $this->em->flush();
        $this->audit->log('institution.update', $actor, 'Institution', (string) $institution->getId(), $before, $institution->toArray());

        return Json::write($response, $institution->toArray());
    }

    private function firstAdmin(Institution $institution): ?User
    {
        return $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')
            ->andWhere('r.code IN (:codes)')
            ->setParameter('inst', $institution)
            ->setParameter('codes', ['school_admin', 'tutor_admin'])
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
