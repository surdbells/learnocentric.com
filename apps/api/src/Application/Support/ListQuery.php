<?php

declare(strict_types=1);

namespace App\Application\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Parses standard list query parameters: pagination, search, sort, and filters.
 * Pagination is opt-in: if neither `page` nor `per_page` is present the caller
 * should return the full (unpaginated) collection for backward compatibility.
 */
final class ListQuery
{
    public int $page = 1;
    public int $perPage = 10;
    public string $q = '';
    public ?string $sort = null;
    public string $order = 'asc';
    /** @var array<string,string> */
    public array $filters = [];
    public bool $paginated = false;

    private const MAX_PER_PAGE = 100;

    /**
     * @param string[] $allowedSorts  sort keys the client may request
     * @param string[] $allowedFilters filter keys the client may request
     */
    public static function fromRequest(Request $request, array $allowedSorts = [], array $allowedFilters = [], ?string $defaultSort = null): self
    {
        $params = $request->getQueryParams();
        $self = new self();

        $self->paginated = isset($params['page']) || isset($params['per_page']) || isset($params['perPage']);
        $self->page = max(1, (int) ($params['page'] ?? 1));
        $per = (int) ($params['per_page'] ?? $params['perPage'] ?? 10);
        $self->perPage = max(1, min(self::MAX_PER_PAGE, $per));

        $self->q = trim((string) ($params['q'] ?? $params['search'] ?? ''));

        $sort = $params['sort'] ?? $defaultSort;
        if ($sort !== null && in_array($sort, $allowedSorts, true)) {
            $self->sort = (string) $sort;
        } elseif ($defaultSort !== null && in_array($defaultSort, $allowedSorts, true)) {
            $self->sort = $defaultSort;
        }
        $self->order = strtolower((string) ($params['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        foreach ($allowedFilters as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $self->filters[$key] = (string) $params[$key];
            }
        }

        return $self;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
