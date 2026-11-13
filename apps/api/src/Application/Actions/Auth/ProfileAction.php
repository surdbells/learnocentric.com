<?php

declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Support\Json;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/auth/profile, read; PUT, update own profile fields. */
final class ProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        if (strtoupper($request->getMethod()) === 'GET') {
            return Json::write($response, ['user' => $user->toOwnArray()]);
        }

        $before = $user->toArray();
        $body = (array) $request->getParsedBody();

        if (isset($body['firstName'])) { $user->setFirstName((string) $body['firstName']); }
        if (isset($body['lastName'])) { $user->setLastName((string) $body['lastName']); }
        if (array_key_exists('phone', $body)) { $user->setPhone($body['phone'] !== null ? (string) $body['phone'] : null); }
        if (array_key_exists('address', $body)) { $user->setAddress($body['address'] !== null ? (string) $body['address'] : null); }
        if (array_key_exists('dateOfBirth', $body)) {
            $dob = trim((string) ($body['dateOfBirth'] ?? ''));
            if ($dob === '') {
                $user->setDateOfBirth(null);
            } else {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($dob, 0, 10));
                if ($d === false || $d > new \DateTimeImmutable('today')) {
                    return Json::error($response, 'Enter a valid date of birth.', 422);
                }
                $user->setDateOfBirth($d);
            }
        }
        if (array_key_exists('profileImageUrl', $body)) { $user->setProfileImageUrl($body['profileImageUrl'] !== null ? (string) $body['profileImageUrl'] : null); }

        $this->em->flush();
        $this->audit->log('user.profile.update', $user, 'User', (string) $user->getId(), $before, $user->toArray());

        return Json::write($response, ['user' => $user->toOwnArray()]);
    }
}
