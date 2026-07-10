<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Staff gradebook: scores per assessment and per student, with the Academic
 * Performance and Competency Transfer tracks kept separate (spec §14).
 */
final class GradebookAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /assessment/gradebook — published assessments with attempt stats. */
    public function overview(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $filters = $request->getQueryParams();

        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('a.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)
            ->orderBy('a.createdAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if (!empty($filters['subject_id'])) {
            $qb->andWhere('a.subject = :sid')->setParameter('sid', (int) $filters['subject_id']);
        }
        if (!empty($filters['track'])) {
            $qb->andWhere('a.track = :tr')->setParameter('tr', $filters['track']);
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $assessment) {
            /** @var Assessment $assessment */
            $rows[] = ['id' => $assessment->getId(), 'title' => $assessment->getTitle(), 'subject' => $assessment->getSubject()->getName(),
                'type' => $assessment->getType(), 'track' => $assessment->getTrack(), 'total_marks' => $assessment->totalMarks()]
                + $this->stats($this->gradedFor($assessment));
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** GET /assessment/gradebook/{id} — every attempt for one assessment plus a summary. */
    public function assessment(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->guard($request, $response)) !== null) {
            return $guard;
        }
        $assessment = $this->em->getRepository(Assessment::class)->find((int) $args['id']);
        if ($assessment === null) {
            return Json::error($response, 'Assessment not found.', 404);
        }
        $attempts = $this->gradedFor($assessment);
        $data = array_map(static fn (AssessmentAttempt $a) => [
            'attempt_id' => $a->getId(),
            'student_id' => $a->getStudent()->getId(),
            'student' => $a->getStudent()->getFirstName() . ' ' . $a->getStudent()->getLastName(),
            'score' => $a->getScore(),
            'total_marks' => $a->getTotalMarks(),
            'percentage' => $a->getPercentage(),
            'passed' => $a->isPassed(),
            'submitted_at' => $a->getSubmittedAt()?->format(DATE_ATOM),
        ], $attempts);

        return Json::write($response, [
            'assessment' => ['id' => $assessment->getId(), 'title' => $assessment->getTitle(), 'track' => $assessment->getTrack(),
                'total_marks' => $assessment->totalMarks(), 'pass_mark' => $assessment->getPassMark()],
            'summary' => $this->stats($attempts),
            'attempts' => $data,
        ]);
    }

    /** GET /assessment/gradebook/students — per-student rollup, academic vs competency kept apart. */
    public function students(Request $request, Response $response): Response
    {
        if (($guard = $this->guard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $weighting = $this->weighting($institution);

        $qb = $this->em->createQueryBuilder()->select('at')->from(AssessmentAttempt::class, 'at')
            ->join('at.assessment', 'a')->join('a.subject', 's')->join('at.student', 'st')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }

        /** @var array<int, array{student:string, academic: float[], competency: float[], attempts:int, passed:int}> $byStudent */
        $byStudent = [];
        foreach ($qb->getQuery()->getResult() as $attempt) {
            /** @var AssessmentAttempt $attempt */
            $sid = $attempt->getStudent()->getId();
            $byStudent[$sid] ??= ['student_id' => $sid, 'student' => $attempt->getStudent()->getFirstName() . ' ' . $attempt->getStudent()->getLastName(),
                'academic' => [], 'competency' => [], 'attempts' => 0, 'passed' => 0];
            $byStudent[$sid][$attempt->getTrack() === 'competency' ? 'competency' : 'academic'][] = (float) $attempt->getPercentage();
            $byStudent[$sid]['attempts']++;
            $byStudent[$sid]['passed'] += $attempt->isPassed() ? 1 : 0;
        }

        // Spec §14 keeps the academic and competency tracks separate by default. A school
        // can opt in (grading.weighting.portfolio_into_academic) to ALSO surface a blended
        // figure; the two separate averages are never overwritten.
        $blend = $weighting['portfolio_into_academic'];
        $p = $weighting['portfolio_percent'] / 100;

        $rows = array_map(static function (array $r) use ($blend, $p): array {
            $avg = static fn (array $v) => empty($v) ? null : round(array_sum($v) / count($v), 1);
            $academic = $avg($r['academic']);
            $competency = $avg($r['competency']);
            $row = ['student_id' => $r['student_id'], 'student' => $r['student'], 'attempts' => $r['attempts'], 'passed' => $r['passed'],
                'academic_avg' => $academic, 'competency_avg' => $competency];
            if ($blend) {
                // Only compute when at least one track has data; a missing track contributes 0.
                $row['blended_avg'] = ($academic === null && $competency === null)
                    ? null
                    : round(($academic ?? 0.0) * (1 - $p) + ($competency ?? 0.0) * $p, 1);
            }
            return $row;
        }, array_values($byStudent));

        return Json::write($response, [
            'data' => $rows,
            'meta' => ['total' => count($rows)],
            'weighting' => ['active' => $weighting['portfolio_into_academic'], 'portfolio_percent' => $weighting['portfolio_percent']],
        ]);
    }

    /**
     * The institution's portfolio-into-academic weighting policy, defaulting to full
     * track separation (spec §14) when unset or when there is no institution scope.
     *
     * @return array{portfolio_into_academic: bool, portfolio_percent: int}
     */
    private function weighting(?Institution $institution): array
    {
        $settings = $institution?->getSettings() ?? [];
        $grading = is_array($settings['grading'] ?? null) ? $settings['grading'] : [];
        $w = is_array($grading['weighting'] ?? null) ? $grading['weighting'] : [];

        return [
            'portfolio_into_academic' => ($w['portfolio_into_academic'] ?? false) === true,
            'portfolio_percent' => max(0, min(100, (int) ($w['portfolio_percent'] ?? 0))),
        ];
    }

    /** @return AssessmentAttempt[] */
    private function gradedFor(Assessment $assessment): array
    {
        return $this->em->getRepository(AssessmentAttempt::class)
            ->findBy(['assessment' => $assessment, 'status' => AssessmentAttempt::GRADED], ['percentage' => 'DESC']);
    }

    /** @param AssessmentAttempt[] $attempts */
    private function stats(array $attempts): array
    {
        $count = count($attempts);
        if ($count === 0) {
            return ['attempts' => 0, 'average' => null, 'pass_rate' => null, 'highest' => null, 'lowest' => null];
        }
        $percentages = array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $attempts);
        $passed = count(array_filter($attempts, static fn (AssessmentAttempt $a) => $a->isPassed()));

        return [
            'attempts' => $count,
            'average' => round(array_sum($percentages) / $count, 1),
            'pass_rate' => round($passed / $count * 100, 1),
            'highest' => max($percentages),
            'lowest' => min($percentages),
        ];
    }

    private function guard(Request $request, Response $response): ?Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can view the gradebook.', 403);
        }
        return null;
    }
}
