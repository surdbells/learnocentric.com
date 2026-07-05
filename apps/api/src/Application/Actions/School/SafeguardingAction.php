<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\SafeguardingCase;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Safeguarding register (spec §20). Any staff member may REPORT a concern, but
 * only designated leads (institution admins) may VIEW or MANAGE the cases.
 */
final class SafeguardingAction
{
    use ResolvesInstitution;

    /** Who may raise a concern. */
    private const REPORTERS = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];
    /** Who may see and manage the register. */
    private const LEADS = ['school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->report($request, $response),
            'PUT' => $this->update($request, $response),
            default => $this->list($request, $response),
        };
    }

    /** GET /safeguarding/cases — the register (leads only). */
    private function list(Request $request, Response $response): Response
    {
        if (($guard = $this->leadGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['status', 'category', 'created_at'], ['status', 'category'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('c')->from(SafeguardingCase::class, 'c');
        if ($institution !== null) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(c.summary) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('c.status = :st')->setParameter('st', $query->filters['status']);
        }
        if (isset($query->filters['category'])) {
            $qb->andWhere('c.category = :cat')->setParameter('cat', $query->filters['category']);
        }

        $mapper = static fn (SafeguardingCase $c) => $c->toArray();
        if (!$query->paginated) {
            $qb->orderBy('c.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        return Json::write($response, Paginator::paginate($qb, 'c', $query, ['status' => 'c.status', 'category' => 'c.category', 'created_at' => 'c.createdAt'], $mapper));
    }

    /** POST /safeguarding/cases — report a concern (any staff). */
    private function report(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), self::REPORTERS, true)) {
            return Json::error($response, 'You are not permitted to report a concern here.', 403);
        }
        $body = (array) $request->getParsedBody();
        $summary = trim((string) ($body['summary'] ?? ''));
        if ($summary === '') {
            return Json::error($response, 'A short summary of the concern is required.', 422);
        }

        $case = new SafeguardingCase($summary);
        $case->setReportedBy($user);
        $case->setInstitution($user->getInstitution());
        $case->setCategory((string) ($body['category'] ?? 'welfare'));
        $case->setDetails(!empty($body['details']) ? (string) $body['details'] : null);
        if (!empty($body['student_id'])) {
            $student = $this->em->getRepository(User::class)->find((int) $body['student_id']);
            if ($student !== null && $student->getRole()->getCode() === 'student') {
                $case->setStudent($student);
            }
        }
        $case->setStatus(SafeguardingCase::REPORTED);
        $this->em->persist($case);

        $this->notifyLeads($case, $user);
        $this->em->flush();
        $this->audit->log('safeguarding.report', $user, 'SafeguardingCase', (string) $case->getId(), null, ['category' => $case->getCategory()]);

        // The reporter gets a confirmation, not the managed record.
        return Json::write($response, ['reported' => true, 'reference' => 'SG-' . str_pad((string) $case->getId(), 5, '0', STR_PAD_LEFT)], 201);
    }

    /** PUT /safeguarding/cases — manage a case (leads only). */
    private function update(Request $request, Response $response): Response
    {
        if (($guard = $this->leadGuard($request, $response)) !== null) {
            return $guard;
        }
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $case = $this->em->getRepository(SafeguardingCase::class)->find((int) ($body['id'] ?? 0));
        if ($case === null) {
            return Json::error($response, 'Case not found.', 404);
        }
        $before = $case->toArray();
        if (isset($body['category'])) {
            $case->setCategory((string) $body['category']);
        }
        if (isset($body['status'])) {
            $case->setStatus((string) $body['status']);
            $case->setHandledBy($user);
            $case->setClosedAt($case->getStatus() === SafeguardingCase::CLOSED ? new DateTimeImmutable() : null);
        }
        if (array_key_exists('outcome', $body)) {
            $case->setOutcome($body['outcome'] !== '' ? (string) $body['outcome'] : null);
        }
        $this->em->flush();
        $this->audit->log('safeguarding.update', $user, 'SafeguardingCase', (string) $case->getId(), $before, $case->toArray());

        return Json::write($response, $case->toArray());
    }

    private function notifyLeads(SafeguardingCase $case, User $reporter): void
    {
        $institution = $reporter->getInstitution();
        if ($institution === null) {
            return;
        }
        $leads = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code IN (:roles)')->andWhere('u.institution = :inst')
            ->setParameter('roles', ['school_admin', 'tutor_admin'])->setParameter('inst', $institution)
            ->getQuery()->getResult();
        foreach ($leads as $lead) {
            $this->notify->notify(
                $lead,
                'safeguarding',
                'New safeguarding concern reported',
                'A ' . $case->getCategory() . ' concern was reported. Review it in the safeguarding register.',
                '/admin/management/safeguarding',
            );
        }
    }

    private function leadGuard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::LEADS, true)) {
            return Json::error($response, 'Safeguarding cases are restricted to designated leads.', 403);
        }
        return null;
    }
}
