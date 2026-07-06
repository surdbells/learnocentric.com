<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Role;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /backend/teacher/students/{id} — the students a teacher works with:
 * everyone enrolled in a class they lead. If the teacher leads no class yet,
 * it falls back to the institution's students so the roster isn't empty.
 */
final class TeacherStudentsAction
{
    use ResolvesInstitution;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        /** @var User $teacher */
        $teacher = $request->getAttribute('user');

        $rows = [];
        $classes = $this->em->getRepository(SchoolClass::class)->findBy(['classTeacher' => $teacher]);
        if ($classes !== []) {
            $enrollments = $this->em->createQueryBuilder()
                ->select('e')->from(Enrollment::class, 'e')
                ->where('e.schoolClass IN (:classes)')->setParameter('classes', $classes)
                ->getQuery()->getResult();
            foreach ($enrollments as $enrollment) {
                /** @var Enrollment $enrollment */
                $student = $enrollment->getStudent();
                $rows[$student->getId()] = $this->row($student, $enrollment->getSchoolClass());
            }
        }

        // Fallback: no class led yet → show the institution's students.
        if ($rows === []) {
            foreach ($this->usersByRole($this->em, Role::STUDENT, $teacher->getInstitution()) as $student) {
                $rows[$student->getId()] = $this->row($student, null);
            }
        }

        return Json::write($response, array_values($rows));
    }

    private function row(User $student, ?SchoolClass $class): array
    {
        return [
            'id' => $student->getId(),
            'first_name' => $student->getFirstName(),
            'last_name' => $student->getLastName(),
            'email' => $student->getEmail(),
            'phone' => $student->getPhone(),
            'class_id' => $class?->getId(),
            'class_name' => $class?->getLabel(),
            'profile_image_url' => $student->getProfileImageUrl(),
            'is_active' => $student->getStatus() === 'active',
        ];
    }
}
