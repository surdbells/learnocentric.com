<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/admin/institutions/{id} */
final class GetInstitutionAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $institution = $this->em->getRepository(Institution::class)->find((int) $args['id']);
        if ($institution === null) {
            return Json::error($response, 'Institution not found.', 404);
        }

        return Json::write($response, $institution->toArray());
    }
}
