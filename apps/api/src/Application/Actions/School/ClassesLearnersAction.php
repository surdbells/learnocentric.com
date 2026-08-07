<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Application\Support\Json;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Intervention;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\TeacherAssignment;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * School-Admin "Classes & Learners" unified hub — KPIs, the class list with
 * per-class stats, and a per-class learner roster with real average scores,
 * derived risk status and intervention flags. Backed by classes, enrolments,
 * graded attempts and interventions. Admission number, gender and attendance
 * are not modelled, so they are omitted rather than fabricated; "current topic"
 * is the highest-week published topic for the class.
 */
final class ClassesLearnersAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];
    private const MASTERY = 50; // below this graded average = "below mastery" / at risk

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /school/classes-learners — KPI strip + class cards. */
    public function hub(Request $request, Response $response): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $classes = $this->em->getRepository(SchoolClass::class)->findBy($institution !== null ? ['institution' => $institution] : []);

        $rows = [];
        $totalEnrolments = 0;
        foreach ($classes as $class) {
            /** @var SchoolClass $class */
            $cid = $class->getId();
            $learners = (int) $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $cid]);
            $totalEnrolments += $learners;
            $stat = $this->classScore($cid);
            $rows[] = [
                'id' => $cid,
                'label' => $class->getLabel(),
                'teacher' => $this->classTeacher($class),
                'learners' => $learners,
                'avg_score' => $stat,
                'current_topic' => $this->currentTopic($class),
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['label'], $b['label']));

        $totalLearners = $institution !== null
            ? (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
                ->where('u.institution = :i')->andWhere('r.code = :s')->setParameter('i', $institution)->setParameter('s', 'student')
                ->getQuery()->getSingleScalarResult()
            : 0;

        return Json::write($response, [
            'kpis' => [
                'total_classes' => count($rows),
                'total_learners' => $totalLearners,
                'active_enrollments' => $totalEnrolments,
                'avg_class_size' => count($rows) > 0 ? round($totalEnrolments / count($rows), 1) : 0,
            ],
            'classes' => $rows,
        ]);
    }

    /** GET /school/classes/{id}/roster — a class's learners + overview stats. */
    public function roster(Request $request, Response $response, array $args): Response
    {
        if (($g = $this->staffGuard($request, $response)) !== null) {
            return $g;
        }
        $class = $this->em->getRepository(SchoolClass::class)->find((int) $args['id']);
        if ($class === null || !$this->canActWithin($request, $class->getInstitution())) {
            return Json::error($response, 'Class not found.', 404);
        }

        $enrolments = $this->em->getRepository(Enrollment::class)->findBy(['schoolClass' => $class]);
        $learners = [];
        $scoreSum = 0.0;
        $scoreCount = 0;
        $belowMastery = 0;
        $needingIntervention = 0;
        foreach ($enrolments as $e) {
            $student = $e->getStudent();
            $avg = $this->studentScore($student);
            $hasIntervention = $this->hasOpenIntervention($student);
            $status = $avg === null ? 'new' : ($avg < self::MASTERY ? 'at_risk' : 'active');
            if ($avg !== null) {
                $scoreSum += $avg;
                $scoreCount++;
                if ($avg < self::MASTERY) {
                    $belowMastery++;
                }
            }
            if ($hasIntervention) {
                $needingIntervention++;
            }
            $learners[] = [
                'id' => $student->getId(),
                'name' => trim($student->getFirstName() . ' ' . $student->getLastName()),
                'email' => $student->getEmail(),
                'avg_score' => $avg,
                'status' => $status,
                'intervention' => $hasIntervention,
            ];
        }
        usort($learners, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        $total = count($enrolments);
        return Json::write($response, [
            'overview' => [
                'id' => $class->getId(),
                'label' => $class->getLabel(),
                'class_teacher' => $this->classTeacher($class),
                'total_learners' => $total,
                'avg_score' => $scoreCount > 0 ? (int) round($scoreSum / $scoreCount) : null,
            ],
            'stats' => [
                'below_mastery' => $belowMastery,
                'below_mastery_pct' => $total > 0 ? (int) round($belowMastery / $total * 100) : 0,
                'needing_intervention' => $needingIntervention,
                'needing_intervention_pct' => $total > 0 ? (int) round($needingIntervention / $total * 100) : 0,
            ],
            'learners' => $learners,
        ]);
    }

    // --- helpers ---

    private function classScore(int $classId): ?int
    {
        $avg = $this->em->createQueryBuilder()->select('AVG(at.percentage)')
            ->from(AssessmentAttempt::class, 'at')->join('at.student', 'st')
            ->join(Enrollment::class, 'e', Join::WITH, 'e.student = st')
            ->where('e.schoolClass = :cid')->andWhere('at.status = :g')
            ->setParameter('cid', $classId)->setParameter('g', AssessmentAttempt::GRADED)
            ->getQuery()->getSingleScalarResult();
        return $avg === null ? null : (int) round((float) $avg);
    }

    private function studentScore(User $student): ?int
    {
        $avg = $this->em->createQueryBuilder()->select('AVG(at.percentage)')
            ->from(AssessmentAttempt::class, 'at')
            ->where('at.student = :st')->andWhere('at.status = :g')
            ->setParameter('st', $student)->setParameter('g', AssessmentAttempt::GRADED)
            ->getQuery()->getSingleScalarResult();
        return $avg === null ? null : (int) round((float) $avg);
    }

    private function hasOpenIntervention(User $student): bool
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Intervention::class, 'i')
            ->where('i.student = :st')->andWhere('i.status != :res')
            ->setParameter('st', $student)->setParameter('res', Intervention::RESOLVED)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    private function classTeacher(SchoolClass $class): ?string
    {
        $ta = $this->em->getRepository(TeacherAssignment::class)->findOneBy(['schoolClass' => $class]);
        $t = $ta?->getTeacher();
        return $t ? trim($t->getFirstName() . ' ' . $t->getLastName()) : null;
    }

    private function currentTopic(SchoolClass $class): ?string
    {
        $row = $this->em->createQueryBuilder()->select('t.title')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('s.institution = :inst')->andWhere('t.approvalStatus = :pub')
            ->andWhere('t.schoolClass = :cls OR t.schoolClass IS NULL')
            ->setParameter('inst', $class->getInstitution())->setParameter('pub', Lifecycle::PUBLISHED)->setParameter('cls', $class)
            ->orderBy('t.weekNumber', 'DESC')->setMaxResults(1)
            ->getQuery()->getArrayResult();
        return $row[0]['title'] ?? null;
    }

    private function staffGuard(Request $request, Response $response): ?Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only administrators can view this.', 403);
        }
        return null;
    }
}
