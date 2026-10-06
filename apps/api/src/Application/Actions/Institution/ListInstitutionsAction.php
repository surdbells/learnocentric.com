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
        // Archived institutions are soft-deleted; hide them from the roster.
        $institutions = $this->em->createQueryBuilder()->select('i')->from(Institution::class, 'i')
            ->where('i.status != :archived')->setParameter('archived', 'archived')
            ->orderBy('i.id', 'ASC')->getQuery()->getResult();

        return Json::write($response, array_map(static fn (Institution $i) => $i->toArray(), $institutions));
    }
}
