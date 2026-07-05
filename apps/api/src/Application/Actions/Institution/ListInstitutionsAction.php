<?php

declare(strict_types=1);

namespace App\Application\Actions\Institution;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/admin/institutions */
final class ListInstitutionsAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institutions = $this->em->getRepository(Institution::class)->findBy([], ['id' => 'ASC']);

        return Json::write($response, array_map(static fn (Institution $i) => $i->toArray(), $institutions));
    }
}
