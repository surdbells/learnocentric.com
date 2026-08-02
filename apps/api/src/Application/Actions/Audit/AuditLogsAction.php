<?php

declare(strict_types=1);

namespace App\Application\Actions\Audit;

use App\Application\Support\Json;
use App\Domain\Entity\AuditLog;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/audit-logs — platform audit trail (super admin only).
 *
 * The AuditLog rows are written across the app (logins, role/content/score edits,
 * exports, settings changes); this surfaces them read-only with the actor and
 * institution resolved, plus a derived category + risk level for filtering.
 */
final class AuditLogsAction
{
    /** Action-type fragments that mark a high-risk event. */
    private const HIGH = ['delete', 'role', 'permission', 'score', 'grade', 'settings', 'login_failed', 'takedown', 'suspend'];
    private const MEDIUM = ['update', 'create', 'approve', 'moderate', 'transition', 'assign', 'export', 'onboard'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'Only the platform super admin can view audit logs.', 403);
        }

        $q = $request->getQueryParams();
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 25)));
        $page = max(1, (int) ($q['page'] ?? 1));
        $category = trim((string) ($q['category'] ?? ''));
        $search = trim((string) ($q['q'] ?? ''));

        $base = $this->em->createQueryBuilder()
            ->from(AuditLog::class, 'a')
            ->leftJoin(User::class, 'u', Join::WITH, 'u.id = a.userId')
            ->leftJoin(Institution::class, 'i', Join::WITH, 'i.id = a.institutionId');

        if ($category !== '') {
            $base->andWhere('a.actionType LIKE :cat')->setParameter('cat', $category . '.%');
        }
        if ($search !== '') {
            $base->andWhere('(LOWER(a.actionType) LIKE :s OR LOWER(u.firstName) LIKE :s OR LOWER(u.lastName) LIKE :s OR LOWER(a.objectType) LIKE :s)')
                ->setParameter('s', '%' . strtolower($search) . '%');
        }

        $total = (int) (clone $base)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $rows = $base
            ->select('a.id AS id, a.actionType AS action_type, a.objectType AS object_type, a.objectId AS object_id, a.ipDevice AS ip_device, a.createdAt AS created_at, a.userId AS user_id, u.firstName AS actor_first, u.lastName AS actor_last, i.name AS institution')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getArrayResult();

        $data = array_map(function (array $r): array {
            $action = (string) $r['action_type'];
            $actor = trim(($r['actor_first'] ?? '') . ' ' . ($r['actor_last'] ?? ''));
            return [
                'id' => (int) $r['id'],
                'action_type' => $action,
                'category' => str_contains($action, '.') ? explode('.', $action, 2)[0] : $action,
                'risk' => $this->risk($action),
                'object_type' => $r['object_type'],
                'object_id' => $r['object_id'],
                'actor' => $actor !== '' ? $actor : 'System',
                'institution' => $r['institution'],
                'ip_device' => $r['ip_device'],
                'created_at' => $r['created_at'] instanceof \DateTimeInterface ? $r['created_at']->format(DATE_ATOM) : $r['created_at'],
            ];
        }, $rows);

        return Json::write($response, [
            'data' => $data,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
            'stats' => $this->stats(),
        ]);
    }

    /** Platform-wide totals for the page's KPI cards (independent of the current filter). */
    private function stats(): array
    {
        $count = fn (?callable $where = null): int => (int) (function () use ($where) {
            $qb = $this->em->createQueryBuilder()->select('COUNT(x.id)')->from(AuditLog::class, 'x');
            if ($where) {
                $where($qb);
            }
            return $qb->getQuery()->getSingleScalarResult();
        })();

        $highRisk = $count(function ($qb): void {
            $or = $qb->expr()->orX();
            foreach (self::HIGH as $idx => $k) {
                $or->add('LOWER(x.actionType) LIKE :h' . $idx);
                $qb->setParameter('h' . $idx, '%' . $k . '%');
            }
            $qb->where($or);
        });

        // Distinct categories (action-type prefix) with counts, for the filter tabs.
        $byAction = $this->em->createQueryBuilder()
            ->select('a.actionType AS action, COUNT(a.id) AS c')
            ->from(AuditLog::class, 'a')->groupBy('a.actionType')
            ->getQuery()->getArrayResult();
        $cats = [];
        foreach ($byAction as $row) {
            $action = (string) $row['action'];
            $cat = str_contains($action, '.') ? explode('.', $action, 2)[0] : $action;
            $cats[$cat] = ($cats[$cat] ?? 0) + (int) $row['c'];
        }
        arsort($cats);
        $categories = array_map(static fn ($k, $v) => ['category' => $k, 'count' => $v], array_keys($cats), array_values($cats));

        return [
            'total' => $count(),
            'high_risk' => $highRisk,
            'failed_logins' => $count(fn ($qb) => $qb->where("x.actionType = 'auth.login_failed'")),
            'categories' => $categories,
        ];
    }

    private function risk(string $action): string
    {
        $a = strtolower($action);
        foreach (self::HIGH as $k) {
            if (str_contains($a, $k)) {
                return 'high';
            }
        }
        foreach (self::MEDIUM as $k) {
            if (str_contains($a, $k)) {
                return 'medium';
            }
        }
        return 'low';
    }
}
