<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/school/profile, the signed-in admin's own institution profile
 * (name, type, address, logo, brand colour, contact). Read by any authed member
 * of the institution; only a school/tutor admin may update it.
 */
final class SchoolProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return strtoupper($request->getMethod()) === 'PUT'
            ? $this->update($request, $response)
            : $this->show($request, $response);
    }

    private function show(Request $request, Response $response): Response
    {
        $institution = $this->institution($request);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        return Json::write($response, $institution->toArray());
    }

    private function update(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if (!in_array($user?->getRole()->getCode(), ['school_admin', 'tutor_admin'], true)) {
            return Json::error($response, 'Only a school administrator can update the profile.', 403);
        }
        $institution = $this->institution($request);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }

        $body = (array) $request->getParsedBody();
        $before = $institution->toArray();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name !== '') {
            $institution->setName($name);
        }
        if (array_key_exists('type', $body) && in_array($body['type'], ['school', 'academy'], true)) {
            $institution->setType((string) $body['type']);
        }
        if (array_key_exists('address', $body)) {
            $institution->setAddress($this->nullableStr($body['address']));
        }
        if (array_key_exists('logo_url', $body)) {
            $institution->setLogoUrl($this->nullableStr($body['logo_url']));
        }
        if (array_key_exists('brand_color', $body)) {
            $branding = $institution->getBranding() ?? [];
            $color = $this->nullableStr($body['brand_color']);
            if ($color === null) {
                unset($branding['color']);
            } else {
                $branding['color'] = $color;
            }
            $institution->setBranding($branding !== [] ? $branding : null);
        }
        if (is_array($body['admin_contact'] ?? null)) {
            $c = $body['admin_contact'];
            $institution->setAdminContact([
                'name' => trim((string) ($c['name'] ?? '')),
                'email' => trim((string) ($c['email'] ?? '')),
                'phone' => trim((string) ($c['phone'] ?? '')),
            ]);
        }

        $this->em->flush();
        $this->audit->log('institution.update', $user, 'Institution', (string) $institution->getId(), $before, $institution->toArray());

        return Json::write($response, $institution->toArray());
    }

    private function institution(Request $request): ?Institution
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        return $user?->getInstitution();
    }

    private function nullableStr(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
