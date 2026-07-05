<?php

declare(strict_types=1);

namespace App\Application\Support;

use Doctrine\ORM\QueryBuilder;

/**
 * Applies sort + pagination to a Doctrine QueryBuilder and returns a standard
 * `{ data, meta }` envelope.
 */
final class Paginator
{
    /**
     * @param string $rootAlias the QB root alias (e.g. 's')
     * @param array<string,string> $sortMap maps client sort keys -> DQL expressions
     * @param callable $mapper fn(object): array — serialises each entity
     * @return array{data: array<int,array>, meta: array<string,mixed>}
     */
    public static function paginate(QueryBuilder $qb, string $rootAlias, ListQuery $query, array $sortMap, callable $mapper): array
    {
        $countQb = clone $qb;
        $total = (int) $countQb
            ->select("COUNT(DISTINCT {$rootAlias}.id)")
            ->resetDQLPart('orderBy')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getQuery()
            ->getSingleScalarResult();

        if ($query->sort !== null && isset($sortMap[$query->sort])) {
            $qb->orderBy($sortMap[$query->sort], strtoupper($query->order));
        }

        $qb->setFirstResult($query->offset())->setMaxResults($query->perPage);
        $items = array_map($mapper, $qb->getQuery()->getResult());

        return [
            'data' => array_values($items),
            'meta' => [
                'total' => $total,
                'page' => $query->page,
                'per_page' => $query->perPage,
                'total_pages' => max(1, (int) ceil($total / $query->perPage)),
                'sort' => $query->sort,
                'order' => $query->order,
                'q' => $query->q,
            ],
        ];
    }
}
