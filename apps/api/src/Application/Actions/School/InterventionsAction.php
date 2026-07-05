<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Intervention;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** /backend/school/interventions — flag and track student support (staff). */
final class InterventionsAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->list($request, $response),
        };
    }

    private function list(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['status', 'due_date', 'created_at'], ['student_id', 'assigned_to', 'subject_id', 'status'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('i')->from(Intervention::class, 'i')->join('i.student', 'st');
        if ($institution !== null) {
            $qb->andWhere('st.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(i.reason) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('i.status = :st')->setParameter('st', $query->filters['status']);
        }
        if (isset($query->filters['student_id'])) {
            $qb->andWhere('i.student = :sid')->setParameter('sid', (int) $query->filters['student_id']);
        }
        if (isset($query->filters['assigned_to'])) {
            $qb->andWhere('i.assignedTo = :aid')->setParameter('aid', (int) $query->filters['assigned_to']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('i.subject = :subid')->setParameter('subid', (int) $query->filters['subject_id']);
        }

        $mapper = static fn (Intervention $i) => $i->toArray();
        if (!$query->paginated) {
            $qb->orderBy('i.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        return Json::write($response, Paginator::paginate($qb, 'i', $query, ['status' => 'i.status', 'due_date' => 'i.dueDate', 'created_at' => 'i.createdAt'], $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $student = $this->em->getRepository(User::class)->find((int) ($body['student_id'] ?? 0));
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($student === null || $student->getRole()->getCode() !== 'student') {
            return Json::error($response, 'A valid student is required.', 422);
        }
        if ($reason === '') {
            return Json::error($response, 'Describe the concern or weak area.', 422);
        }

        $intervention = new Intervention($student, $reason);
        $intervention->setRaisedBy($request->getAttribute('user'));
        $intervention->setStatus(Intervention::OPEN);
        $this->applyFields($intervention, $body);
        $this->em->persist($intervention);

        $this->notifyAssignee($intervention, 'New intervention assigned');
        $this->em->flush();
        $this->audit->log('intervention.create', $request->getAttribute('user'), 'Intervention', (string) $intervention->getId(), null, $intervention->toArray());

        return Json::write($response, $intervention->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $intervention = $this->em->getRepository(Intervention::class)->find((int) ($body['id'] ?? 0));
        if ($intervention === null) {
            return Json::error($response, 'Intervention not found.', 404);
        }
        $before = $intervention->toArray();
        $priorAssignee = $intervention->getAssignedTo()?->getId();

        if (isset($body['reason']) && trim((string) $body['reason']) !== '') {
            $intervention->setReason((string) $body['reason']);
        }
        if (isset($body['status'])) {
            $intervention->setStatus((string) $body['status']);
            if ($intervention->getStatus() === Intervention::RESOLVED && $intervention->getResolvedAt() === null) {
                $intervention->setResolvedAt(new DateTimeImmutable());
            }
            if ($intervention->getStatus() !== Intervention::RESOLVED) {
                $intervention->setResolvedAt(null);
            }
        }
        if (array_key_exists('outcome', $body)) {
            $intervention->setOutcome($body['outcome'] !== '' ? (string) $body['outcome'] : null);
        }
        $this->applyFields($intervention, $body);

        // Notify a newly-assigned staff member.
        if ($intervention->getAssignedTo() !== null && $intervention->getAssignedTo()->getId() !== $priorAssignee) {
            $this->notifyAssignee($intervention, 'Intervention assigned to you');
        }
        $this->em->flush();
        $this->audit->log('intervention.update', $request->getAttribute('user'), 'Intervention', (string) $intervention->getId(), $before, $intervention->toArray());

        return Json::write($response, $intervention->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if ($intervention === null) {
            return Json::error($response, 'Intervention not found.', 404);
        }
        $this->em->remove($intervention);
        $this->em->flush();
        $this->audit->log('intervention.delete', $request->getAttribute('user'), 'Intervention', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(Intervention::class)->findBy(['id' => $ids]) as $intervention) {
            $this->em->remove($intervention);
            $count++;
        }
        $this->em->flush();

        return Json::write($response, ['deleted' => $count]);
    }

    private function applyFields(Intervention $intervention, array $body): void
    {
        if (array_key_exists('subject_id', $body)) {
            $intervention->setSubject(!empty($body['subject_id']) ? $this->em->getRepository(Subject::class)->find((int) $body['subject_id']) : null);
        }
        if (array_key_exists('topic_id', $body)) {
            $intervention->setTopic(!empty($body['topic_id']) ? $this->em->getRepository(Topic::class)->find((int) $body['topic_id']) : null);
        }
        if (array_key_exists('assigned_to_id', $body)) {
            $intervention->setAssignedTo(!empty($body['assigned_to_id']) ? $this->em->getRepository(User::class)->find((int) $body['assigned_to_id']) : null);
        }
        if (array_key_exists('due_date', $body)) {
            $intervention->setDueDate(!empty($body['due_date']) ? new DateTimeImmutable((string) $body['due_date']) : null);
        }
    }

    private function notifyAssignee(Intervention $intervention, string $title): void
    {
        $assignee = $intervention->getAssignedTo();
        if ($assignee === null) {
            return;
        }
        $link = $assignee->getRole()->getCode() === 'teacher'
            ? '/teacher/academics/interventions'
            : '/admin/academics/interventions';
        $this->notify->notify(
            $assignee,
            'intervention',
            $title . ': ' . $intervention->getStudent()->getFirstName() . ' ' . $intervention->getStudent()->getLastName(),
            $intervention->getReason(),
            $link,
        );
    }

    private function staffGuard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can manage interventions.', 403);
        }
        return null;
    }
}
