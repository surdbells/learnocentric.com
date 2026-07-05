<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/school/enrollments — GET list, POST create, PUT update, DELETE remove. */
final class EnrollmentsAction
{
    use ResolvesInstitution;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
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

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Enrollment::class, 'e')
            ->join('e.schoolClass', 'c')
            ->orderBy('e.id', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('c.institution = :inst')->setParameter('inst', $institution);
        }

        return Json::write($response, array_map(static fn (Enrollment $e) => $e->toArray(), $qb->getQuery()->getResult()));
    }

    private function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $student = $this->em->getRepository(User::class)->find((int) ($body['studentId'] ?? $body['student_id'] ?? 0));
        $class = $this->em->getRepository(SchoolClass::class)->find((int) ($body['classId'] ?? $body['class_id'] ?? 0));
        if ($student === null || $class === null) {
            return Json::error($response, 'Valid studentId and classId are required.', 422);
        }

        $enrollment = new Enrollment($student, $class);
        $this->applyDate($enrollment, $body);
        $this->em->persist($enrollment);
        try {
            $this->em->flush();
        } catch (Throwable) {
            return Json::error($response, 'This student is already enrolled in that class.', 409);
        }

        $this->audit->log('enrollment.create', $request->getAttribute('user'), 'Enrollment', (string) $enrollment->getId(), null, $enrollment->toArray());

        return Json::write($response, $enrollment->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $enrollment = $this->em->getRepository(Enrollment::class)->find((int) ($body['id'] ?? 0));
        if ($enrollment === null) {
            return Json::error($response, 'Enrollment not found.', 404);
        }
        $before = $enrollment->toArray();
        if (!empty($body['classId']) || !empty($body['class_id'])) {
            $class = $this->em->getRepository(SchoolClass::class)->find((int) ($body['classId'] ?? $body['class_id']));
            if ($class !== null) {
                $enrollment->setSchoolClass($class);
            }
        }
        if (!empty($body['status'])) {
            $enrollment->setStatus((string) $body['status']);
        }
        $this->applyDate($enrollment, $body);
        $this->em->flush();

        $this->audit->log('enrollment.update', $request->getAttribute('user'), 'Enrollment', (string) $enrollment->getId(), $before, $enrollment->toArray());

        return Json::write($response, $enrollment->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $enrollment = $this->em->getRepository(Enrollment::class)->find($id);
        if ($enrollment === null) {
            return Json::error($response, 'Enrollment not found.', 404);
        }
        $before = $enrollment->toArray();
        $this->em->remove($enrollment);
        $this->em->flush();

        $this->audit->log('enrollment.delete', $request->getAttribute('user'), 'Enrollment', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    private function applyDate(Enrollment $enrollment, array $body): void
    {
        $date = $body['enrollmentDate'] ?? $body['enrollment_date'] ?? null;
        if (!empty($date)) {
            try {
                $enrollment->setEnrollmentDate(new DateTimeImmutable((string) $date));
            } catch (Throwable) {
                // ignore invalid date
            }
        }
    }
}
