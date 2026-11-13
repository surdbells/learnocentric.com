<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/school/signup, a school's self sign-up setting and join code.
 *  GET  → {enabled, join_code, platform_allows}
 *  PUT  {enabled} → open/close sign-up (a code is issued the first time it opens)
 *  POST /school/signup/regenerate → new join code (the old one stops working)
 */
final class SignupSettingsAction
{
    use ResolvesInstitution;

    private const ADMINS = [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::SUPER_ADMIN];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlatformSettings $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (($deny = Guard::roles($request, $response, self::ADMINS, 'Only school admins can manage sign-up.')) !== null) {
            return $deny;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }

        if (strtoupper($request->getMethod()) === 'PUT') {
            $body = (array) $request->getParsedBody();
            $enabled = (bool) ($body['enabled'] ?? false);
            if ($enabled && !$this->settings->bool('registration.allow_self_signup')) {
                return Json::error($response, 'Self sign-up is switched off for the whole platform.', 409);
            }
            $institution->setSelfSignup($enabled);
            if ($enabled && $institution->getJoinCode() === null) {
                $institution->regenerateJoinCode();
            }
            $this->em->flush();
            $this->audit->log('institution.signup', Guard::user($request), 'Institution', (string) $institution->getId(), null, ['enabled' => $enabled]);
        }

        return Json::write($response, $this->state($institution));
    }

    public function regenerate(Request $request, Response $response): Response
    {
        if (($deny = Guard::roles($request, $response, self::ADMINS, 'Only school admins can manage sign-up.')) !== null) {
            return $deny;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $institution->regenerateJoinCode();
        $this->em->flush();
        $this->audit->log('institution.join_code', Guard::user($request), 'Institution', (string) $institution->getId(), null, null);

        return Json::write($response, $this->state($institution));
    }

    private function state(\App\Domain\Entity\Institution $institution): array
    {
        return [
            'enabled' => $institution->isSelfSignup(),
            'join_code' => $institution->getJoinCode(),
            'platform_allows' => $this->settings->bool('registration.allow_self_signup'),
        ];
    }
}
