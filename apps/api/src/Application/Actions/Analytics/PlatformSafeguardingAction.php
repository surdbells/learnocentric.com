<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Support\Json;
use App\Domain\Entity\Institution;
use App\Domain\Entity\SafeguardingCase;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platform-wide safeguarding & compliance surface for the super admin: cases
 * across every institution, escalation triage, and a designated-lead compliance
 * view. The school action stays institution-scoped; this one governs all tenants.
 */
final class PlatformSafeguardingAction
{
    /** Statuses that count as still open (i.e. not closed). */
    private const OPEN = [SafeguardingCase::REPORTED, SafeguardingCase::UNDER_REVIEW, SafeguardingCase::ESCALATED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /platform/safeguarding/overview — cross-institution stats + compliance. */
    public function overview(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }

        /** @var SafeguardingCase[] $cases */
        $cases = $this->em->createQueryBuilder()->select('c', 'i')->from(SafeguardingCase::class, 'c')
            ->leftJoin('c.institution', 'i')->getQuery()->getResult();

        $byStatus = array_fill_keys(SafeguardingCase::STATUSES, 0);
        $bySeverity = array_fill_keys(SafeguardingCase::SEVERITIES, 0);
        $byCategory = array_fill_keys(SafeguardingCase::CATEGORIES, 0);
        $perInst = [];
        $escalated = 0;
        $critical = 0;
        $open = 0;

        foreach ($cases as $c) {
            $byStatus[$c->getStatus()] = ($byStatus[$c->getStatus()] ?? 0) + 1;
            $bySeverity[$c->getSeverity()] = ($bySeverity[$c->getSeverity()] ?? 0) + 1;
            $byCategory[$c->getCategory()] = ($byCategory[$c->getCategory()] ?? 0) + 1;
            $isOpen = in_array($c->getStatus(), self::OPEN, true);
            if ($isOpen) {
                $open++;
            }
            if ($c->getStatus() === SafeguardingCase::ESCALATED) {
                $escalated++;
            }
            if ($c->getSeverity() === SafeguardingCase::SEVERITY_CRITICAL && $isOpen) {
                $critical++;
            }
            $inst = $c->getInstitution();
            $iid = $inst?->getId() ?? 0;
            $perInst[$iid] ??= ['id' => $inst?->getId(), 'name' => $inst?->getName() ?? 'Unassigned', 'total' => 0, 'open' => 0, 'escalated' => 0];
            $perInst[$iid]['total']++;
            $perInst[$iid]['open'] += $isOpen ? 1 : 0;
            $perInst[$iid]['escalated'] += $c->getStatus() === SafeguardingCase::ESCALATED ? 1 : 0;
        }

        // Compliance: which institutions have named a designated safeguarding lead.
        $withLead = 0;
        $institutions = $this->em->getRepository(Institution::class)->findAll();
        $leadById = [];
        foreach ($institutions as $inst) {
            /** @var Institution $inst */
            $safe = $inst->getSettings()['safeguarding'] ?? [];
            $hasLead = !empty($safe['lead_name']) || !empty($safe['lead_email']);
            $leadById[$inst->getId()] = $hasLead;
            if ($hasLead) {
                $withLead++;
            }
        }
        foreach ($perInst as &$row) {
            $row['has_lead'] = $row['id'] !== null ? ($leadById[$row['id']] ?? false) : false;
        }
        unset($row);
        $instRows = array_values($perInst);
        usort($instRows, static fn ($a, $b) => $b['open'] <=> $a['open'] ?: strcmp((string) $a['name'], (string) $b['name']));

        return Json::write($response, [
            'stats' => [
                'total' => count($cases),
                'open' => $open,
                'escalated' => $escalated,
                'critical_open' => $critical,
                'closed' => $byStatus[SafeguardingCase::CLOSED] ?? 0,
            ],
            'by_status' => $this->pairs($byStatus, 'status'),
            'by_severity' => $this->pairs($bySeverity, 'severity'),
            'by_category' => $this->pairs($byCategory, 'category'),
            'institutions' => $instRows,
            'compliance' => [
                'total' => count($institutions),
                'with_lead' => $withLead,
                'without_lead' => count($institutions) - $withLead,
            ],
        ]);
    }

    /** GET /platform/safeguarding/cases — every case, institution-tagged, filterable. */
    public function cases(Request $request, Response $response): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        $params = $request->getQueryParams();

        $qb = $this->em->createQueryBuilder()->select('c', 'i')->from(SafeguardingCase::class, 'c')
            ->leftJoin('c.institution', 'i')->orderBy('c.createdAt', 'DESC');
        if (!empty($params['status'])) {
            $qb->andWhere('c.status = :st')->setParameter('st', (string) $params['status']);
        }
        if (!empty($params['severity'])) {
            $qb->andWhere('c.severity = :sv')->setParameter('sv', (string) $params['severity']);
        }
        if (!empty($params['category'])) {
            $qb->andWhere('c.category = :cat')->setParameter('cat', (string) $params['category']);
        }
        if (!empty($params['institution_id'])) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', (int) $params['institution_id']);
        }
        if (!empty($params['q'])) {
            $qb->andWhere('LOWER(c.summary) LIKE :q')->setParameter('q', '%' . strtolower((string) $params['q']) . '%');
        }

        $rows = array_map(function (SafeguardingCase $c) {
            return $c->toArray() + [
                'institution_id' => $c->getInstitution()?->getId(),
                'institution' => $c->getInstitution()?->getName() ?? 'Unassigned',
            ];
        }, $qb->getQuery()->getResult());

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** PUT /platform/safeguarding/{id} — triage a case (status / severity / outcome). */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->guard($request, $response)) !== null) {
            return $g;
        }
        /** @var User $user */
        $user = $request->getAttribute('user');
        $case = $this->em->getRepository(SafeguardingCase::class)->find((int) $args['id']);
        if ($case === null) {
            return Json::error($response, 'Case not found.', 404);
        }
        $before = $case->toArray();
        $body = (array) $request->getParsedBody();

        if (isset($body['status']) && in_array($body['status'], SafeguardingCase::STATUSES, true)) {
            $case->setStatus((string) $body['status']);
            $case->setHandledBy($user);
            $case->setClosedAt($case->getStatus() === SafeguardingCase::CLOSED ? new DateTimeImmutable() : null);
        }
        if (isset($body['severity']) && in_array($body['severity'], SafeguardingCase::SEVERITIES, true)) {
            $case->setSeverity((string) $body['severity']);
        }
        if (array_key_exists('outcome', $body)) {
            $case->setOutcome($body['outcome'] !== '' ? (string) $body['outcome'] : null);
        }
        $this->em->flush();
        $this->audit->log('safeguarding.platform_update', $user, 'SafeguardingCase', (string) $case->getId(), $before, $case->toArray());

        return Json::write($response, $case->toArray() + [
            'institution_id' => $case->getInstitution()?->getId(),
            'institution' => $case->getInstitution()?->getName() ?? 'Unassigned',
        ]);
    }

    // --- helpers ---

    /**
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function pairs(array $counts, string $key): array
    {
        $out = [];
        foreach ($counts as $k => $v) {
            $out[] = [$key => $k, 'count' => $v];
        }
        return $out;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || $user->getRole()->getCode() !== 'super_admin') {
            return Json::error($response, 'The platform safeguarding register is restricted to the platform owner.', 403);
        }
        return null;
    }
}
