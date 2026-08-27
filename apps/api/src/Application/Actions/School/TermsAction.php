<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Term;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/school/terms, academic terms in the institution's sessions. */
final class TermsAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('t')->from(Term::class, 't')->join('t.session', 'ses')
            ->orderBy('t.sequence', 'ASC');
        if ($institution !== null) {
            $qb->andWhere('ses.institution = :inst')->setParameter('inst', $institution);
        }

        return Json::write($response, array_map(static fn (Term $t) => $t->toArray(), $qb->getQuery()->getResult()));
    }
}
