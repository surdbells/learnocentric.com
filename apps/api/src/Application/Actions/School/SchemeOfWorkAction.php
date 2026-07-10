<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\SchemeOfWork;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Term;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use App\Service\LifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/school/scheme-of-work — term/week curriculum plan per class + subject. */
final class SchemeOfWorkAction
{
    use ResolvesInstitution;

    private const EDITABLE = [Lifecycle::DRAFT, Lifecycle::REVIEW];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LifecycleService $lifecycle,
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
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['week_number', 'status'], ['class_id', 'subject_id', 'term_id', 'status'], 'week_number');

        $qb = $this->em->createQueryBuilder()->select('sow')->from(SchemeOfWork::class, 'sow')->join('sow.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(sow.objective) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        foreach (['status' => 'sow.status'] as $key => $field) {
            if (isset($query->filters[$key])) {
                $qb->andWhere("$field = :$key")->setParameter($key, $query->filters[$key]);
            }
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('sow.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('sow.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['term_id'])) {
            $qb->andWhere('sow.term = :tid')->setParameter('tid', (int) $query->filters['term_id']);
        }

        $mapper = static fn (SchemeOfWork $s) => $s->toArray();
        if (!$query->paginated) {
            $qb->orderBy('sow.weekNumber', 'ASC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }

        return Json::write($response, Paginator::paginate($qb, 'sow', $query, ['week_number' => 'sow.weekNumber', 'status' => 'sow.status'], $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $class = $this->em->getRepository(SchoolClass::class)->find((int) ($body['class_id'] ?? 0));
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? 0));
        $week = (int) ($body['week_number'] ?? 0);
        if ($class === null || $subject === null || $week < 1) {
            return Json::error($response, 'A class, subject and week number are required.', 422);
        }

        $term = !empty($body['term_id']) ? $this->em->getRepository(Term::class)->find((int) $body['term_id']) : null;
        if ($this->duplicateWeek($class, $subject, $term, $week, null)) {
            return Json::error($response, 'That week already has a scheme entry for this class, subject and term.', 409);
        }

        $scheme = new SchemeOfWork($class, $subject, $week);
        $scheme->setTerm($term);
        $scheme->setStatus('draft');
        $this->applyFields($scheme, $body);
        $this->em->persist($scheme);
        $this->em->flush();
        $this->audit->log('scheme.create', $request->getAttribute('user'), 'SchemeOfWork', (string) $scheme->getId(), null, $scheme->toArray());

        return Json::write($response, $scheme->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $scheme = $this->em->getRepository(SchemeOfWork::class)->find((int) ($body['id'] ?? 0));
        if ($scheme === null) {
            return Json::error($response, 'Scheme entry not found.', 404);
        }
        if (!in_array($scheme->getStatus(), self::EDITABLE, true)) {
            return Json::error($response, "This scheme entry is {$scheme->getStatus()}; return it to draft before editing.", 409);
        }
        $before = $scheme->toArray();
        if (isset($body['week_number'])) {
            $scheme->setWeekNumber(max(1, (int) $body['week_number']));
        }
        if (array_key_exists('term_id', $body)) {
            $scheme->setTerm(!empty($body['term_id']) ? $this->em->getRepository(Term::class)->find((int) $body['term_id']) : null);
        }
        $this->applyFields($scheme, $body);
        $this->em->flush();
        $this->audit->log('scheme.update', $request->getAttribute('user'), 'SchemeOfWork', (string) $scheme->getId(), $before, $scheme->toArray());

        return Json::write($response, $scheme->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $scheme = $this->em->getRepository(SchemeOfWork::class)->find($id);
        if ($scheme === null) {
            return Json::error($response, 'Scheme entry not found.', 404);
        }
        $this->em->remove($scheme);
        $this->em->flush();
        $this->audit->log('scheme.delete', $request->getAttribute('user'), 'SchemeOfWork', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) (($request->getParsedBody()['ids'] ?? [])))));
        if (empty($ids)) {
            return Json::error($response, 'No ids provided.', 422);
        }
        $count = 0;
        foreach ($this->em->getRepository(SchemeOfWork::class)->findBy(['id' => $ids]) as $scheme) {
            $this->em->remove($scheme);
            $count++;
        }
        $this->em->flush();

        return Json::write($response, ['deleted' => $count]);
    }

    /** POST /backend/school/scheme-of-work/{id}/transition — body { to, note } */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $scheme = $this->em->getRepository(SchemeOfWork::class)->find((int) $args['id']);
        if ($scheme === null) {
            return Json::error($response, 'Scheme entry not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->lifecycle->transition($scheme, (string) ($body['to'] ?? ''), $request->getAttribute('user'), $body['note'] ?? null);
        } catch (Throwable $e) {
            return Json::error($response, $e->getMessage(), 422);
        }

        return Json::write($response, ['scheme' => $scheme->toArray()] + $result);
    }

    /** GET /backend/school/scheme-of-work/{id}/history */
    public function history(Request $request, Response $response, array $args): Response
    {
        $scheme = $this->em->getRepository(SchemeOfWork::class)->find((int) $args['id']);
        if ($scheme === null) {
            return Json::error($response, 'Scheme entry not found.', 404);
        }

        return Json::write($response, array_map(static fn ($v) => $v->toArray(), $this->lifecycle->history($scheme)));
    }

    private function applyFields(SchemeOfWork $scheme, array $body): void
    {
        if (array_key_exists('topic_id', $body)) {
            $scheme->setTopic(!empty($body['topic_id']) ? $this->em->getRepository(Topic::class)->find((int) $body['topic_id']) : null);
        }
        if (array_key_exists('objective', $body)) {
            $scheme->setObjective($body['objective'] !== '' ? (string) $body['objective'] : null);
        }
        if (array_key_exists('assigned_teacher_id', $body)) {
            $scheme->setAssignedTeacher(!empty($body['assigned_teacher_id']) ? $this->em->getRepository(User::class)->find((int) $body['assigned_teacher_id']) : null);
        }
    }

    private function duplicateWeek(SchoolClass $class, Subject $subject, ?Term $term, int $week, ?int $excludeId): bool
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(sow.id)')->from(SchemeOfWork::class, 'sow')
            ->where('sow.schoolClass = :c')->andWhere('sow.subject = :s')->andWhere('sow.weekNumber = :w')
            ->setParameter('c', $class)->setParameter('s', $subject)->setParameter('w', $week);
        if ($term !== null) {
            $qb->andWhere('sow.term = :t')->setParameter('t', $term);
        } else {
            $qb->andWhere('sow.term IS NULL');
        }
        if ($excludeId !== null) {
            $qb->andWhere('sow.id != :ex')->setParameter('ex', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
