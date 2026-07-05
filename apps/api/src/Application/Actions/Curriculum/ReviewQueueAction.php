<?php

declare(strict_types=1);

namespace App\Application\Actions\Curriculum;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Topic;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /backend/curriculum/review-queue — content awaiting approval. */
final class ReviewQueueAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :st')->setParameter('st', Lifecycle::REVIEW)
            ->orderBy('t.updatedAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        $topics = array_map(static function (Topic $t): array {
            return [
                'type' => 'Topic',
                'id' => $t->getId(),
                'title' => $t->getTitle(),
                'subject' => $t->getSubject()->getName(),
                'status' => $t->getApprovalStatus(),
                'updated_at' => $t->getUpdatedAt()->format(DATE_ATOM),
            ];
        }, $qb->getQuery()->getResult());

        return Json::write($response, ['data' => $topics, 'meta' => ['total' => count($topics)]]);
    }
}
