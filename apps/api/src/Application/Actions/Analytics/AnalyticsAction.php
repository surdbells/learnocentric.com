<?php

declare(strict_types=1);

namespace App\Application\Actions\Analytics;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\FeedbackNote;
use App\Domain\Entity\GuardianLink;
use App\Domain\Entity\Intervention;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\LiveClassAttendance;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Entity\Worksheet;
use App\Domain\Entity\WorksheetSubmission;
use App\Domain\Lifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Analytics rollups (spec §16): a school-level overview for staff and a
 * per-student progress report for the student, their guardian, or staff -
 * always keeping academic marks and competency ratings separate.
 */
final class AnalyticsAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** GET /analytics/overview, staff dashboard rollup. */
    public function overview(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can view analytics.', 403);
        }
        $institution = $this->resolveInstitution($request, $this->em);

        // --- Quiz performance per subject (academic track source of truth) ---
        $attempts = $this->gradedAttempts($institution);
        $bySubject = [];
        foreach ($attempts as $attempt) {
            $name = $attempt->getAssessment()->getSubject()->getName();
            $bySubject[$name] ??= ['subject' => $name, 'attempts' => 0, 'sum' => 0.0, 'passed' => 0];
            $bySubject[$name]['attempts']++;
            $bySubject[$name]['sum'] += (float) $attempt->getPercentage();
            $bySubject[$name]['passed'] += $attempt->isPassed() ? 1 : 0;
        }
        $quizBySubject = array_values(array_map(static fn (array $r) => [
            'subject' => $r['subject'],
            'attempts' => $r['attempts'],
            'average' => round($r['sum'] / max(1, $r['attempts']), 1),
            'pass_rate' => round($r['passed'] / max(1, $r['attempts']) * 100, 1),
        ], $bySubject));

        // --- Worksheets ---
        $submissions = $this->worksheetSubmissions($institution);
        $gradedSubs = array_filter($submissions, static fn (WorksheetSubmission $s) => $s->getStatus() === WorksheetSubmission::GRADED);
        $scoreSum = 0.0;
        foreach ($gradedSubs as $s) {
            $max = max(1, $s->getWorksheet()->getTotalMarks());
            $scoreSum += ($s->getScore() ?? 0) / $max * 100;
        }

        // --- Portfolio rating distribution (competency track, kept separate) ---
        $ratings = array_fill_keys(PortfolioEntry::RATINGS, 0);
        $pendingPortfolio = 0;
        foreach ($this->portfolioEntries($institution) as $entry) {
            if ($entry->getStatus() === PortfolioEntry::REVIEWED && $entry->getCompetencyRating() !== null) {
                $ratings[$entry->getCompetencyRating()] = ($ratings[$entry->getCompetencyRating()] ?? 0) + 1;
            } else {
                $pendingPortfolio++;
            }
        }

        // --- Live class attendance ---
        [$held, $joins, $enrolledSeats] = $this->attendanceStats($institution);

        // --- Feedback loop health ---
        [$sent, $acknowledged] = $this->feedbackStats($institution);

        return Json::write($response, [
            'counts' => [
                'students' => $this->countByRole('student', $institution),
                'teachers' => $this->countByRole('teacher', $institution),
                'published_topics' => $this->countPublished(Topic::class, 'approvalStatus', $institution, 't'),
                'published_assessments' => $this->countPublished(Assessment::class, 'approvalStatus', $institution, 'a'),
                'published_worksheets' => $this->countPublished(Worksheet::class, 'approvalStatus', $institution, 'w'),
                'live_classes' => $held,
            ],
            'quiz_by_subject' => $quizBySubject,
            'worksheets' => [
                'submissions' => count($submissions),
                'graded' => count($gradedSubs),
                'average' => count($gradedSubs) ? round($scoreSum / count($gradedSubs), 1) : null,
            ],
            'portfolio' => ['ratings' => $ratings, 'pending' => $pendingPortfolio],
            'attendance' => [
                'classes_held' => $held,
                'joins' => $joins,
                'rate' => $enrolledSeats > 0 ? round($joins / $enrolledSeats * 100, 1) : null,
            ],
            'feedback' => [
                'sent' => $sent,
                'acknowledged' => $acknowledged,
                'ack_rate' => $sent > 0 ? round($acknowledged / $sent * 100, 1) : null,
            ],
            'performance_trend' => $this->performanceTrend($attempts),
            'mastery_distribution' => $this->masteryDistribution($attempts),
            'topic_mastery' => $this->topicMastery($attempts),
            'learners_attention' => $this->learnersAttention($attempts),
        ]);
    }

    /**
     * Average graded-attempt score per month (last 6) for the performance-trend line.
     *
     * @param AssessmentAttempt[] $attempts
     * @return array<int, array{month:string, average:float|null}>
     */
    private function performanceTrend(array $attempts): array
    {
        $now = new \DateTimeImmutable();
        $months = [];
        $order = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = $now->modify("first day of -$i month")->format('Y-m');
            $order[] = $key;
            $months[$key] = ['sum' => 0.0, 'n' => 0];
        }
        foreach ($attempts as $a) {
            $when = $a->getSubmittedAt();
            if ($when === null) {
                continue;
            }
            $key = $when->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['sum'] += (float) $a->getPercentage();
                $months[$key]['n']++;
            }
        }
        return array_map(static fn (string $k) => [
            'month' => $k,
            'average' => $months[$k]['n'] > 0 ? round($months[$k]['sum'] / $months[$k]['n'], 1) : null,
        ], $order);
    }

    /**
     * Learner mastery bands from graded-attempt percentages.
     *
     * @param AssessmentAttempt[] $attempts
     * @return array{strong:int, good:int, developing:int, weak:int}
     */
    private function masteryDistribution(array $attempts): array
    {
        $b = ['strong' => 0, 'good' => 0, 'developing' => 0, 'weak' => 0];
        foreach ($attempts as $a) {
            $p = (float) $a->getPercentage();
            if ($p >= 80) {
                $b['strong']++;
            } elseif ($p >= 60) {
                $b['good']++;
            } elseif ($p >= 40) {
                $b['developing']++;
            } else {
                $b['weak']++;
            }
        }
        return $b;
    }

    /**
     * Average score per topic (via the assessment's topic), with a mastery band.
     *
     * @param AssessmentAttempt[] $attempts
     * @return array<int, array{topic:string, subject:string, average:float, mastery:string, count:int}>
     */
    private function topicMastery(array $attempts): array
    {
        $by = [];
        foreach ($attempts as $a) {
            $topic = $a->getAssessment()->getTopic();
            if ($topic === null) {
                continue;
            }
            $tid = $topic->getId();
            $by[$tid] ??= ['topic' => $topic->getTitle(), 'subject' => $a->getAssessment()->getSubject()->getName(), 'sum' => 0.0, 'n' => 0];
            $by[$tid]['sum'] += (float) $a->getPercentage();
            $by[$tid]['n']++;
        }
        $out = array_map(function (array $r) {
            $avg = round($r['sum'] / max(1, $r['n']), 1);
            return ['topic' => $r['topic'], 'subject' => $r['subject'], 'average' => $avg, 'mastery' => $this->masteryBand($avg), 'count' => $r['n']];
        }, array_values($by));
        usort($out, static fn ($a, $b) => $b['average'] <=> $a['average']);
        return $out;
    }

    /**
     * Learners whose average is below mastery (< 60%), for the attention rail.
     *
     * @param AssessmentAttempt[] $attempts
     * @return array<int, array{student:string, average:float, band:string}>
     */
    private function learnersAttention(array $attempts): array
    {
        $by = [];
        foreach ($attempts as $a) {
            $sid = $a->getStudent()->getId();
            $by[$sid] ??= ['student' => $a->getStudent()->getFirstName() . ' ' . $a->getStudent()->getLastName(), 'sum' => 0.0, 'n' => 0];
            $by[$sid]['sum'] += (float) $a->getPercentage();
            $by[$sid]['n']++;
        }
        $out = [];
        foreach ($by as $r) {
            $avg = round($r['sum'] / max(1, $r['n']), 1);
            if ($avg < 60) {
                $out[] = ['student' => $r['student'], 'average' => $avg, 'band' => $this->masteryBand($avg)];
            }
        }
        usort($out, static fn ($a, $b) => $a['average'] <=> $b['average']);
        return array_slice($out, 0, 6);
    }

    private function masteryBand(float $avg): string
    {
        return $avg >= 80 ? 'Strong' : ($avg >= 60 ? 'Good' : ($avg >= 40 ? 'Developing' : 'Weak'));
    }

    /**
     * One learner's quiz average + mastery per subject, for the subject-progress table.
     *
     * @param AssessmentAttempt[] $attempts (the learner's academic attempts)
     * @return array<int, array{subject:string, quiz_average:float, attempts:int, mastery:string, last_topic:?string}>
     */
    private function subjectProgressForStudent(array $attempts): array
    {
        $by = [];
        foreach ($attempts as $a) {
            $name = $a->getAssessment()->getSubject()->getName();
            $by[$name] ??= ['subject' => $name, 'sum' => 0.0, 'n' => 0, 'last_topic' => $a->getAssessment()->getTopic()?->getTitle()];
            $by[$name]['sum'] += (float) $a->getPercentage();
            $by[$name]['n']++;
        }
        $out = array_map(function (array $r) {
            $avg = round($r['sum'] / max(1, $r['n']), 1);
            return ['subject' => $r['subject'], 'quiz_average' => $avg, 'attempts' => $r['n'], 'mastery' => $this->masteryBand($avg), 'last_topic' => $r['last_topic']];
        }, array_values($by));
        usort($out, static fn ($a, $b) => $b['quiz_average'] <=> $a['quiz_average']);
        return $out;
    }

    /** Portfolio competency ratings → a 0–100 progress score (kept out of academic marks). */
    private const COMPETENCY_PCT = ['emerging' => 25, 'developing' => 50, 'proficient' => 75, 'mastery' => 100];

    /**
     * Per-topic skill progress from REVIEWED portfolio work, the competency track.
     * Each reviewed entry's rating maps to a score; scores are averaged per topic.
     *
     * @param PortfolioEntry[] $portfolio
     * @return array<int,array{topic:string,value:int,level:string,count:int}>
     */
    private function competencySkills(array $portfolio): array
    {
        $byTopic = [];
        foreach ($portfolio as $p) {
            if ($p->getStatus() !== PortfolioEntry::REVIEWED) {
                continue;
            }
            $rating = $p->getCompetencyRating();
            if ($rating === null || !isset(self::COMPETENCY_PCT[$rating])) {
                continue;
            }
            $topic = $p->getTopic()->getTitle();
            $byTopic[$topic] ??= ['sum' => 0, 'count' => 0];
            $byTopic[$topic]['sum'] += self::COMPETENCY_PCT[$rating];
            $byTopic[$topic]['count']++;
        }
        $out = [];
        foreach ($byTopic as $topic => $agg) {
            $avg = (int) round($agg['sum'] / $agg['count']);
            $out[] = ['topic' => $topic, 'value' => $avg, 'level' => $this->competencyBand($avg), 'count' => $agg['count']];
        }
        usort($out, static fn ($a, $b) => $b['value'] <=> $a['value']);
        return $out;
    }

    /** Overall competency score (0–100) across reviewed portfolio work, or null if none. */
    private function competencyAverage(array $portfolio): ?int
    {
        $sum = 0;
        $count = 0;
        foreach ($portfolio as $p) {
            if ($p->getStatus() !== PortfolioEntry::REVIEWED) {
                continue;
            }
            $rating = $p->getCompetencyRating();
            if ($rating === null || !isset(self::COMPETENCY_PCT[$rating])) {
                continue;
            }
            $sum += self::COMPETENCY_PCT[$rating];
            $count++;
        }
        return $count ? (int) round($sum / $count) : null;
    }

    /** Map a 0–100 competency score back to its nearest competency band. */
    private function competencyBand(int $pct): string
    {
        if ($pct >= 88) {
            return 'mastery';
        }
        if ($pct >= 63) {
            return 'proficient';
        }
        if ($pct >= 38) {
            return 'developing';
        }
        return 'emerging';
    }

    /**
     * GET /analytics/school-report, school-wide performance report: headline
     * figures, class × subject performance, a subject summary (school average +
     * highest/lowest class + pass rate + status) and priority attention areas.
     * All computed from graded attempts; nothing synthesised.
     */
    public function schoolReport(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can view reports.', 403);
        }
        $institution = $this->resolveInstitution($request, $this->em);

        // Class × subject averages.
        $qb = $this->em->createQueryBuilder()
            ->select('sc.id AS cid', 'sc.level AS level', 'sc.arm AS arm', 's.name AS subject', 'AVG(at.percentage) AS avg', 'COUNT(at.id) AS n',
                'SUM(CASE WHEN at.passed = true THEN 1 ELSE 0 END) AS passed')
            ->from(AssessmentAttempt::class, 'at')->join('at.assessment', 'a')->join('a.subject', 's')
            ->join('at.student', 'st')->join(Enrollment::class, 'e', \Doctrine\ORM\Query\Expr\Join::WITH, 'e.student = st')
            ->join('e.schoolClass', 'sc')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED)
            ->groupBy('sc.id')->addGroupBy('sc.level')->addGroupBy('sc.arm')->addGroupBy('s.name');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        $rows = $qb->getQuery()->getArrayResult();

        $bySubject = [];
        $byClass = [];
        $classSet = [];
        $allSum = 0.0;
        $allN = 0;
        foreach ($rows as $r) {
            $avg = round((float) $r['avg'], 1);
            $subject = (string) $r['subject'];
            $class = trim($r['level'] . ' ' . ($r['arm'] ?? ''));
            $classSet[$class] = true;
            $allSum += (float) $r['avg'] * (int) $r['n'];
            $allN += (int) $r['n'];

            $bySubject[$subject] ??= ['subject' => $subject, 'classes' => [], 'sum' => 0.0, 'n' => 0, 'passed' => 0, 'high' => null, 'low' => null];
            $bySubject[$subject]['classes'][] = ['class' => $class, 'average' => $avg];
            $bySubject[$subject]['sum'] += (float) $r['avg'] * (int) $r['n'];
            $bySubject[$subject]['n'] += (int) $r['n'];
            $bySubject[$subject]['passed'] += (int) $r['passed'];
            if ($bySubject[$subject]['high'] === null || $avg > $bySubject[$subject]['high']['average']) {
                $bySubject[$subject]['high'] = ['class' => $class, 'average' => $avg];
            }
            if ($bySubject[$subject]['low'] === null || $avg < $bySubject[$subject]['low']['average']) {
                $bySubject[$subject]['low'] = ['class' => $class, 'average' => $avg];
            }

            $byClass[$class] ??= ['class' => $class, 'sum' => 0.0, 'n' => 0];
            $byClass[$class]['sum'] += (float) $r['avg'] * (int) $r['n'];
            $byClass[$class]['n'] += (int) $r['n'];
        }

        $schoolAvg = $allN > 0 ? round($allSum / $allN, 1) : null;
        $status = static fn (float $a) => $a >= 75 ? 'Good' : ($a >= 60 ? 'Monitor' : 'Needs attention');
        $subjectSummary = array_map(static function (array $s) use ($status) {
            $avg = $s['n'] > 0 ? round($s['sum'] / $s['n'], 1) : 0.0;
            return [
                'subject' => $s['subject'],
                'school_average' => $avg,
                'highest_class' => $s['high'],
                'lowest_class' => $s['low'],
                'pass_rate' => $s['n'] > 0 ? round($s['passed'] / $s['n'] * 100, 1) : 0.0,
                'status' => $status($avg),
            ];
        }, array_values($bySubject));
        usort($subjectSummary, static fn ($a, $b) => $b['school_average'] <=> $a['school_average']);

        // Class × subject for the grouped bars: subjects with per-class averages.
        $classList = array_keys($classSet);
        sort($classList);
        $classBySubject = array_map(static function (array $s) use ($classList) {
            $map = [];
            foreach ($s['classes'] as $c) {
                $map[$c['class']] = $c['average'];
            }
            return ['subject' => $s['subject'], 'values' => array_map(static fn ($c) => $map[$c] ?? 0, $classList)];
        }, array_values($bySubject));

        // Top class by average.
        $topClass = null;
        foreach ($byClass as $c) {
            $avg = $c['n'] > 0 ? round($c['sum'] / $c['n'], 1) : 0.0;
            if ($topClass === null || $avg > $topClass['average']) {
                $topClass = ['class' => $c['class'], 'average' => $avg];
            }
        }

        // Priority attention areas: subjects/classes below mastery.
        $priorities = [];
        foreach ($subjectSummary as $s) {
            if ($s['school_average'] < 60) {
                $priorities[] = 'Low ' . $s['subject'] . ' average (' . $s['school_average'] . '%)';
            }
        }
        if ($subjectSummary !== [] && ($low = end($subjectSummary)) && $low['lowest_class']) {
            $priorities[] = 'Weakest class: ' . $low['lowest_class']['class'] . ' in ' . $low['subject'];
        }

        [$held, $joins, $seats] = $this->attendanceStats($institution);
        [$sent, $ack] = $this->feedbackStats($institution);
        $openInterventions = (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Intervention::class, 'i')->join('i.student', 'st')
            ->where('i.status = :res')->setParameter('res', Intervention::RESOLVED)
            ->getQuery()->getSingleScalarResult();

        return Json::write($response, [
            'kpis' => [
                'school_average' => $schoolAvg,
                'total_learners' => $this->countByRole('student', $institution),
                'classes_analysed' => count($classList),
                'subjects_analysed' => count($bySubject),
                'attendance_average' => $seats > 0 ? round($joins / $seats * 100, 1) : null,
                'report_completion' => $sent > 0 ? round($ack / $sent * 100, 1) : null,
            ],
            'class_list' => $classList,
            'class_by_subject' => $classBySubject,
            'subject_summary' => $subjectSummary,
            'top_class' => $topClass,
            'interventions_resolved' => $openInterventions,
            'priority_areas' => array_slice($priorities, 0, 5),
        ]);
    }

    /**
     * GET /analytics/report-card/{id}, a formal term report card for one learner:
     * per-subject score + letter grade (from the school's grade bands) + remark,
     * an overall grade, attendance and the latest teacher comment. Viewable by the
     * learner, their guardian, or staff.
     */
    public function reportCard(Request $request, Response $response, array $args): Response
    {
        $viewer = $this->currentUser($request);
        $student = $this->em->getRepository(User::class)->find((int) $args['id']);
        if ($student === null || $student->getRole()->getCode() !== 'student') {
            return Json::error($response, 'Student not found.', 404);
        }
        if (!$this->canViewStudent($viewer, $student)) {
            return Json::error($response, 'You are not allowed to view this report card.', 403);
        }

        $institution = $student->getInstitution();
        $bands = $this->gradeBands($institution);

        $attempts = $this->em->getRepository(AssessmentAttempt::class)
            ->findBy(['student' => $student, 'status' => AssessmentAttempt::GRADED]);
        $academic = array_values(array_filter($attempts, static fn (AssessmentAttempt $a) => $a->getTrack() === 'academic'));

        $subjects = array_map(function (array $s) use ($bands) {
            $grade = $this->letterFor($s['quiz_average'], $bands);
            return [
                'subject' => $s['subject'],
                'score' => $s['quiz_average'],
                'grade' => $grade,
                'remark' => $this->remarkFor($s['quiz_average']),
            ];
        }, $this->subjectProgressForStudent($academic));

        $overallAvg = $academic !== []
            ? round(array_sum(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $academic)) / count($academic), 1)
            : null;

        // Attendance (live-class joins vs offered).
        $joined = $this->em->getRepository(LiveClassAttendance::class)->count(['student' => $student]);
        $classIds = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $student]) as $e) {
            $classIds[] = $e->getSchoolClass()->getId();
        }
        $offered = 0;
        $classLabel = null;
        if ($classIds !== []) {
            $offered = (int) $this->em->createQueryBuilder()->select('COUNT(lc.id)')->from(LiveClass::class, 'lc')
                ->where('lc.schoolClass IN (:cids)')->andWhere('lc.status != :c')
                ->setParameter('cids', $classIds)->setParameter('c', LiveClass::CANCELLED)->getQuery()->getSingleScalarResult();
            $enr = $this->em->getRepository(Enrollment::class)->findOneBy(['student' => $student]);
            $classLabel = $enr?->getSchoolClass()->getLabel();
        }

        $latestNote = $this->em->createQueryBuilder()->select('f')->from(FeedbackNote::class, 'f')
            ->where('f.student = :s')->setParameter('s', $student)->orderBy('f.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getResult();
        $teacherComment = $latestNote !== [] ? $latestNote[0]->getMessage() : null;

        return Json::write($response, [
            'school' => $institution?->getName(),
            'student' => ['id' => $student->getId(), 'name' => $student->getFirstName() . ' ' . $student->getLastName(), 'class' => $classLabel],
            'subjects' => $subjects,
            'overall' => [
                'average' => $overallAvg,
                'grade' => $overallAvg === null ? null : $this->letterFor($overallAvg, $bands),
                'remark' => $overallAvg === null ? null : $this->remarkFor($overallAvg),
            ],
            'attendance' => ['joined' => $joined, 'offered' => $offered, 'rate' => $offered > 0 ? round($joined / $offered * 100, 1) : null],
            'teacher_comment' => $teacherComment,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /**
     * The institution's grade bands (high→low), defaulting to a standard A–F scale.
     *
     * @return array<int, array{grade:string, min:int}>
     */
    private function gradeBands(?object $institution): array
    {
        $settings = method_exists($institution, 'getSettings') ? ($institution->getSettings() ?? []) : [];
        $bands = is_array($settings['grading']['bands'] ?? null) ? $settings['grading']['bands'] : [];
        $clean = [];
        foreach ($bands as $b) {
            if (!empty($b['grade'])) {
                $clean[] = ['grade' => (string) $b['grade'], 'min' => max(0, min(100, (int) ($b['min'] ?? 0)))];
            }
        }
        if ($clean === []) {
            $clean = [['grade' => 'A', 'min' => 70], ['grade' => 'B', 'min' => 60], ['grade' => 'C', 'min' => 50], ['grade' => 'D', 'min' => 40], ['grade' => 'F', 'min' => 0]];
        }
        usort($clean, static fn ($a, $b) => $b['min'] <=> $a['min']);
        return $clean;
    }

    /** @param array<int, array{grade:string, min:int}> $bands */
    private function letterFor(float $score, array $bands): string
    {
        foreach ($bands as $b) {
            if ($score >= $b['min']) {
                return $b['grade'];
            }
        }
        return $bands !== [] ? end($bands)['grade'] : '-';
    }

    private function remarkFor(float $score): string
    {
        return $score >= 80 ? 'Excellent' : ($score >= 65 ? 'Very good' : ($score >= 50 ? 'Good' : ($score >= 40 ? 'Fair' : 'Needs improvement')));
    }

    /** GET /analytics/children, students linked to the current guardian. */
    public function children(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $links = $this->em->getRepository(GuardianLink::class)->findBy(['guardian' => $user]);

        return Json::write($response, ['data' => array_map(static fn (GuardianLink $l) => $l->toArray(), $links)]);
    }

    /** GET /analytics/student/{id}, progress report for self, guardian, or staff. */
    public function student(Request $request, Response $response, array $args): Response
    {
        $viewer = $this->currentUser($request);
        $student = $this->em->getRepository(User::class)->find((int) $args['id']);
        if ($student === null || $student->getRole()->getCode() !== 'student') {
            return Json::error($response, 'Student not found.', 404);
        }
        if (!$this->canViewStudent($viewer, $student)) {
            return Json::error($response, 'You are not allowed to view this report.', 403);
        }

        // Quizzes (academic)
        $attempts = $this->em->getRepository(AssessmentAttempt::class)
            ->findBy(['student' => $student, 'status' => AssessmentAttempt::GRADED], ['submittedAt' => 'DESC']);
        $academic = array_filter($attempts, static fn (AssessmentAttempt $a) => $a->getTrack() === 'academic');
        $academicAvg = count($academic)
            ? round(array_sum(array_map(static fn (AssessmentAttempt $a) => (float) $a->getPercentage(), $academic)) / count($academic), 1)
            : null;

        // Worksheets
        $subs = $this->em->getRepository(WorksheetSubmission::class)->findBy(['student' => $student], ['submittedAt' => 'DESC']);
        $gradedSubs = array_values(array_filter($subs, static fn (WorksheetSubmission $s) => $s->getStatus() === WorksheetSubmission::GRADED));
        $wsAvg = null;
        if ($gradedSubs) {
            $sum = 0.0;
            foreach ($gradedSubs as $s) {
                $sum += ($s->getScore() ?? 0) / max(1, $s->getWorksheet()->getTotalMarks()) * 100;
            }
            $wsAvg = round($sum / count($gradedSubs), 1);
        }

        // Portfolio (competency, reported separately, never averaged into marks)
        $portfolio = $this->em->getRepository(PortfolioEntry::class)->findBy(['student' => $student], ['createdAt' => 'DESC']);

        // Feedback
        $notes = $this->em->getRepository(FeedbackNote::class)->findBy(['student' => $student], ['createdAt' => 'DESC']);

        // Structured summary for the parent report (spec §7.5, §18): pull the most
        // recent non-empty value for each field and collect topic misconceptions.
        $mostRecent = static function (callable $get) use ($notes): ?string {
            foreach ($notes as $n) {
                $v = $get($n);
                if ($v !== null && trim($v) !== '') {
                    return $v;
                }
            }
            return null;
        };
        $teacherComment = $mostRecent(static fn (FeedbackNote $n) => $n->getMessage());
        $strengths = $mostRecent(static fn (FeedbackNote $n) => $n->getStrengths());
        $practiceNeeded = $mostRecent(static fn (FeedbackNote $n) => $n->getPracticeNeeded());
        $parentSupport = $mostRecent(static fn (FeedbackNote $n) => $n->getParentSupportSuggestion());
        // Misconceptions to work on, from the known misconceptions of topics the
        // student has received feedback on (topic data already available here).
        $misconceptions = [];
        foreach ($notes as $n) {
            $topic = $n->getTopic();
            if ($topic === null) {
                continue;
            }
            foreach ((array) ($topic->toArray()['misconceptions'] ?? []) as $m) {
                $m = trim((string) $m);
                if ($m !== '' && !in_array($m, $misconceptions, true)) {
                    $misconceptions[] = $m;
                }
            }
        }

        // Live class attendance: joined vs classes offered to their group
        $joined = $this->em->getRepository(LiveClassAttendance::class)->count(['student' => $student]);
        $classIds = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $student]) as $enrollment) {
            $classIds[] = $enrollment->getSchoolClass()->getId();
        }
        $offered = 0;
        if (!empty($classIds)) {
            $offered = (int) $this->em->createQueryBuilder()->select('COUNT(lc.id)')->from(LiveClass::class, 'lc')
                ->where('lc.schoolClass IN (:cids)')->andWhere('lc.status != :cancelled')
                ->setParameter('cids', $classIds)->setParameter('cancelled', LiveClass::CANCELLED)
                ->getQuery()->getSingleScalarResult();
        }

        return Json::write($response, [
            'student' => [
                'id' => $student->getId(),
                'name' => $student->getFirstName() . ' ' . $student->getLastName(),
            ],
            'academic' => [
                'average' => $academicAvg,
                'attempts' => array_map(static fn (AssessmentAttempt $a) => [
                    'assessment' => $a->getAssessment()->getTitle(),
                    'subject' => $a->getAssessment()->getSubject()->getName(),
                    'percentage' => $a->getPercentage(),
                    'passed' => $a->isPassed(),
                    'submitted_at' => $a->getSubmittedAt()?->format(DATE_ATOM),
                ], $attempts),
            ],
            'performance_trend' => $this->performanceTrend($academic),
            'topic_mastery' => $this->topicMastery($academic),
            'subject_progress' => $this->subjectProgressForStudent($academic),
            'worksheets' => [
                'average' => $wsAvg,
                'submissions' => array_map(static fn (WorksheetSubmission $s) => [
                    'worksheet' => $s->getWorksheet()->getTitle(),
                    'status' => $s->getStatus(),
                    'score' => $s->getScore(),
                    'total_marks' => $s->getWorksheet()->getTotalMarks(),
                    'feedback' => $s->getFeedback(),
                ], $subs),
            ],
            'competency' => [
                // Skill progress is the competency track, drawn from reviewed portfolio work.
                'average' => $this->competencyAverage($portfolio),
                'skills' => $this->competencySkills($portfolio),
                'entries' => array_map(static fn (PortfolioEntry $p) => [
                    'title' => $p->getTitle(),
                    'topic' => $p->getTopic()->getTitle(),
                    'status' => $p->getStatus(),
                    'rating' => $p->getCompetencyRating(),
                ], $portfolio),
            ],
            'feedback' => [
                'total' => count($notes),
                'unread' => count(array_filter($notes, static fn (FeedbackNote $n) => !$n->isAcknowledged())),
                // Named, structured fields the parent report renders in separate sections.
                'strengths' => $strengths,
                'misconceptions' => $misconceptions,
                'practice_needed' => $practiceNeeded,
                'parent_support_suggestion' => $parentSupport,
                'teacher_comment' => $teacherComment,
                'recent' => array_map(static fn (FeedbackNote $n) => [
                    'type' => $n->getType(),
                    'message' => $n->getMessage(),
                    'topic' => $n->getTopic()?->getTitle(),
                    'acknowledged' => $n->isAcknowledged(),
                ], array_slice($notes, 0, 5)),
            ],
            'attendance' => [
                'joined' => $joined,
                'offered' => $offered,
                'rate' => $offered > 0 ? round($joined / $offered * 100, 1) : null,
            ],
        ]);
    }

    // --- data helpers ---

    /** @return AssessmentAttempt[] */
    private function gradedAttempts($institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('at')->from(AssessmentAttempt::class, 'at')
            ->join('at.assessment', 'a')->join('a.subject', 's')
            ->where('at.status = :g')->setParameter('g', AssessmentAttempt::GRADED);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return WorksheetSubmission[] */
    private function worksheetSubmissions($institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('ws')->from(WorksheetSubmission::class, 'ws')
            ->join('ws.worksheet', 'w')->join('w.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return PortfolioEntry[] */
    private function portfolioEntries($institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('p')->from(PortfolioEntry::class, 'p')
            ->join('p.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return array{0:int,1:int,2:int} classes held, total joins, total enrolled seats */
    private function attendanceStats($institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->where('lc.status != :cancelled')->setParameter('cancelled', LiveClass::CANCELLED);
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        $held = 0;
        $joins = 0;
        $seats = 0;
        foreach ($qb->getQuery()->getResult() as $lc) {
            /** @var LiveClass $lc */
            $held++;
            $joins += $this->em->getRepository(LiveClassAttendance::class)->count(['liveClass' => $lc]);
            if ($lc->getSchoolClass() !== null) {
                $seats += $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $lc->getSchoolClass()]);
            }
        }
        return [$held, $joins, $seats];
    }

    /** @return array{0:int,1:int} sent, acknowledged */
    private function feedbackStats($institution): array
    {
        $qb = $this->em->createQueryBuilder()->select('f')->from(FeedbackNote::class, 'f')->join('f.student', 'st');
        if ($institution !== null) {
            $qb->andWhere('st.institution = :inst')->setParameter('inst', $institution);
        }
        $notes = $qb->getQuery()->getResult();
        return [count($notes), count(array_filter($notes, static fn (FeedbackNote $n) => $n->isAcknowledged()))];
    }

    private function countByRole(string $role, $institution): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :role')->setParameter('role', $role);
        if ($institution !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $institution);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countPublished(string $entity, string $statusField, $institution, string $alias): int
    {
        $qb = $this->em->createQueryBuilder()->select("COUNT($alias.id)")->from($entity, $alias)
            ->where("$alias.$statusField = :pub")->setParameter('pub', Lifecycle::PUBLISHED);
        if ($institution !== null) {
            // Worksheet reaches Subject via its topic; the others have a direct subject relation.
            if ($entity === Worksheet::class) {
                $qb->join("$alias.topic", 'wt')->join('wt.subject', 's');
            } else {
                $qb->join("$alias.subject", 's');
            }
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function canViewStudent(User $viewer, User $student): bool
    {
        if ($viewer->getId() === $student->getId()) {
            return true;
        }
        $role = $viewer->getRole()->getCode();
        if (in_array($role, self::STAFF, true)) {
            return $viewer->getInstitution() === null
                || $student->getInstitution()?->getId() === $viewer->getInstitution()->getId();
        }
        if ($role === 'parent') {
            return $this->em->getRepository(GuardianLink::class)
                ->findOneBy(['guardian' => $viewer, 'student' => $student]) !== null;
        }
        return false;
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }
}
