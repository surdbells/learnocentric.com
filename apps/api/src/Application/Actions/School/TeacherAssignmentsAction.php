<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Guard;
use App\Application\Support\Json;
use App\Domain\Entity\Role;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Who teaches which subject to which class. These assignments drive a
 * teacher's "My classes", learner roster, tutor inbox and submissions, but
 * could only be created once, at staff onboarding.
 *  GET    /school/teacher-assignments?teacher_id=   the school's assignments (optionally one teacher's)
 *  POST   /school/teacher-assignments               {teacher_id, class_id, subject_id}
 *  DELETE /school/teacher-assignments?id=
 */
final class TeacherAssignmentsAction
{
    use ResolvesInstitution;

    private const ADMIN = [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN, Role::ACADEMIC_LEAD, Role::SUPER_ADMIN];
    private const TEACHING = [Role::TEACHER, Role::ACADEMIC_LEAD, Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $qb = $this->em->createQueryBuilder()->select('ta', 'c', 's', 't')->from(TeacherAssignment::class, 'ta')
            ->join('ta.schoolClass', 'c')->join('ta.subject', 's')->join('ta.teacher', 't')
            ->orderBy('c.level', 'ASC')->addOrderBy('s.name', 'ASC');
        if ($institution !== null) {
            $qb->andWhere('c.institution = :i')->setParameter('i', $institution);
        }
        $teacherId = (int) ($request->getQueryParams()['teacher_id'] ?? 0);
        if ($teacherId > 0) {
            $qb->andWhere('t.id = :tid')->setParameter('tid', $teacherId);
        }

        return Json::write($response, ['data' => array_map(static fn (TeacherAssignment $a) => $a->toArray(), $qb->getQuery()->getResult())]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $body = (array) $request->getParsedBody();
        $teacher = $this->em->getRepository(User::class)->find((int) ($body['teacher_id'] ?? 0));
        $class = $this->em->getRepository(SchoolClass::class)->find((int) ($body['class_id'] ?? 0));
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? 0));
        if ($teacher === null || $class === null || $subject === null) {
            return Json::error($response, 'Choose a teacher, a class and a subject.', 422);
        }
        // Everything must belong to the caller's school, and the person must be teaching staff.
        if (!$this->canActWithin($request, $class->getInstitution())
            || $subject->getInstitution()->getId() !== $class->getInstitution()->getId()
            || $teacher->getInstitution()?->getId() !== $class->getInstitution()->getId()
            || !in_array($teacher->getRole()->effectiveCode(), self::TEACHING, true)) {
            return Json::error($response, 'That teacher, class or subject is not in your school.', 422);
        }
        if ($this->em->getRepository(TeacherAssignment::class)->findOneBy(['teacher' => $teacher, 'schoolClass' => $class, 'subject' => $subject]) !== null) {
            return Json::error($response, 'That teacher already teaches this subject to this class.', 409);
        }
        $assignment = new TeacherAssignment($teacher, $class, $subject);
        $this->em->persist($assignment);
        $this->em->flush();
        $this->audit->log('teacher_assignment.create', $request->getAttribute('user'), 'TeacherAssignment', (string) $assignment->getId(), null, $assignment->toArray());

        return Json::write($response, $assignment->toArray(), 201);
    }

    public function delete(Request $request, Response $response): Response
    {
        if (($deny = $this->guard($request, $response)) !== null) {
            return $deny;
        }
        $assignment = $this->em->getRepository(TeacherAssignment::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($assignment === null || !$this->canActWithin($request, $assignment->getSchoolClass()->getInstitution())) {
            return Json::error($response, 'Assignment not found.', 404);
        }
        $before = $assignment->toArray();
        $id = $assignment->getId();
        $this->em->remove($assignment);
        $this->em->flush();
        $this->audit->log('teacher_assignment.delete', $request->getAttribute('user'), 'TeacherAssignment', (string) $id, $before, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    private function guard(Request $request, Response $response): ?Response
    {
        return Guard::roles($request, $response, self::ADMIN, 'Only administrators can manage teaching assignments.');
    }
}
