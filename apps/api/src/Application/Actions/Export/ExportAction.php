<?php

declare(strict_types=1);

namespace App\Application\Actions\Export;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Domain\Entity\Assessment;
use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\Subject;
use App\Domain\Entity\User;
use App\Domain\Entity\WorksheetSubmission;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Server-side CSV exports for staff (spec §16/§21 reporting).
 *
 * Two exports are offered: a class/subject gradebook and a school performance
 * summary. Both are institution-scoped (the caller can only ever export their
 * own tenant's data) and restricted to staff roles.
 *
 * Every export writes an audit entry via AuditLogger so that data leaving the
 * platform is accountable, this closes the gap where `can_export` grants were
 * effectively dead because exports were neither served here nor audited.
 */
final class ExportAction
{
    use ResolvesInstitution;

    /** Roles permitted to export institutional reports (mirrors GradebookAction/AnalyticsAction). */
    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * GET /export/gradebook, CSV of published assessments (optionally one
     * subject) with attempt statistics. Mirrors GradebookAction::overview.
     */
    public function gradebook(Request $request, Response $response): Response
    {
        $user = $this->staffUser($request);
        if ($user === null) {
            return Json::error($response, 'Only teachers and administrators can export the gradebook.', 403);
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $filters = $request->getQueryParams();
        $subjectId = !empty($filters['subject_id']) ? (int) $filters['subject_id'] : null;
        $track = !empty($filters['track']) ? (string) $filters['track'] : null;

        $qb = $this->em->createQueryBuilder()->select('a')->from(Assessment::class, 'a')->join('a.subject', 's')
            ->where('a.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)
            ->orderBy('s.name', 'ASC')->addOrderBy('a.createdAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($subjectId !== null) {
            $qb->andWhere('a.subject = :sid')->setParameter('sid', $subjectId);
        }
        if ($track !== null) {
            $qb->andWhere('a.track = :tr')->setParameter('tr', $track);
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $assessment) {
            /** @var Assessment $assessment */
            $stats = $this->stats($this->gradedFor($assessment));
            $rows[] = [
                $assessment->getSubject()->getName(),
                $assessment->getTitle(),
                $assessment->getType(),
                $assessment->getTrack(),
                $assessment->totalMarks(),
                $stats['attempts'],
                $stats['average'] ?? '',
                $stats['pass_rate'] ?? '',
                $stats['highest'] ?? '',
                $stats['lowest'] ?? '',
            ];
        }

        $subjectLabel = 'all';
        if ($subjectId !== null) {
            $subject = $this->em->find(Subject::class, $subjectId);
            $subjectLabel = $subject !== null ? $subject->getName() : ('subject-' . $subjectId);
        }

        // Audit BEFORE streaming the file so the export is recorded even if the
        // client aborts the download.
        $this->audit->log(
            'report.export',
            $user,
            'Export',
            'gradebook',
            null,
            ['subject_id' => $subjectId, 'subject' => $subjectLabel, 'track' => $track, 'rows' => count($rows)],
        );

        $header = ['Subject', 'Assessment', 'Type', 'Track', 'Total marks', 'Attempts', 'Average %', 'Pass rate %', 'Highest %', 'Lowest %'];

        return $this->csv($response, 'gradebook', $header, $rows);
    }

    /**
     * GET /export/summary, CSV of the school performance summary: headline
     * counts plus per-subject quiz performance, worksheet and portfolio rollups.
     * Mirrors the figures shown on the analytics overview.
     */
    public function summary(Request $request, Response $response): Response
    {
        $user = $this->staffUser($request);
        if ($user === null) {
            return Json::error($response, 'Only teachers and administrators can export reports.', 403);
        }
        $institution = $this->resolveInstitution($request, $this->em);

        $rows = [];
        $rows[] = ['Section', 'Metric', 'Value'];

        // --- Headline counts ---
        $rows[] = ['Counts', 'Students', $this->countByRole('student', $institution)];
        $rows[] = ['Counts', 'Teachers', $this->countByRole('teacher', $institution)];
        $rows[] = ['Counts', 'Published assessments', $this->countPublishedAssessments($institution)];

        // --- Quiz performance per subject (academic track) ---
        $attempts = $this->gradedAttempts($institution);
        $bySubject = [];
        foreach ($attempts as $attempt) {
            $name = $attempt->getAssessment()->getSubject()->getName();
            $bySubject[$name] ??= ['attempts' => 0, 'sum' => 0.0, 'passed' => 0];
            $bySubject[$name]['attempts']++;
            $bySubject[$name]['sum'] += (float) $attempt->getPercentage();
            $bySubject[$name]['passed'] += $attempt->isPassed() ? 1 : 0;
        }
        $rows[] = ['Quiz by subject', 'Subject', 'Attempts / Average % / Pass rate %'];
        foreach ($bySubject as $name => $r) {
            $avg = round($r['sum'] / max(1, $r['attempts']), 1);
            $passRate = round($r['passed'] / max(1, $r['attempts']) * 100, 1);
            $rows[] = ['Quiz by subject', $name, $r['attempts'] . ' / ' . $avg . ' / ' . $passRate];
        }

        // --- Worksheets ---
        $submissions = $this->worksheetSubmissions($institution);
        $graded = array_filter($submissions, static fn (WorksheetSubmission $s) => $s->getStatus() === WorksheetSubmission::GRADED);
        $scoreSum = 0.0;
        foreach ($graded as $s) {
            $scoreSum += ($s->getScore() ?? 0) / max(1, $s->getWorksheet()->getTotalMarks()) * 100;
        }
        $rows[] = ['Worksheets', 'Submissions', count($submissions)];
        $rows[] = ['Worksheets', 'Graded', count($graded)];
        $rows[] = ['Worksheets', 'Average %', count($graded) ? round($scoreSum / count($graded), 1) : ''];

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
        foreach ($ratings as $rating => $count) {
            $rows[] = ['Portfolio (competency)', ucfirst((string) $rating), $count];
        }
        $rows[] = ['Portfolio (competency)', 'Pending review', $pendingPortfolio];

        $this->audit->log(
            'report.export',
            $user,
            'Export',
            'summary',
            null,
            ['institution_id' => $institution?->getId(), 'rows' => count($rows)],
        );

        // No header row here, the first cell of each row already carries the section.
        return $this->csv($response, 'school-summary', null, $rows);
    }

    // --- CSV writing ---

    /**
     * Build a CSV attachment response.
     *
     * @param string        $slug   filename stem
     * @param string[]|null $header optional header row
     * @param array<int, array<int, scalar|null>> $rows
     */
    private function csv(Response $response, string $slug, ?array $header, array $rows): Response
    {
        $fh = fopen('php://temp', 'r+');
        if ($header !== null) {
            fputcsv($fh, $header);
        }
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        // Prepend a UTF-8 BOM so Excel opens accented names correctly.
        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        $filename = $slug . '-' . date('Ymd') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // --- data helpers (mirror GradebookAction / AnalyticsAction) ---

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

    private function countByRole(string $role, $institution): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.code = :role')->setParameter('role', $role);
        if ($institution !== null) {
            $qb->andWhere('u.institution = :inst')->setParameter('inst', $institution);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countPublishedAssessments($institution): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(Assessment::class, 'a')
            ->where('a.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED);
        if ($institution !== null) {
            $qb->join('a.subject', 's')->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** The authenticated user if they hold a staff role, otherwise null. */
    private function staffUser(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');
        if ($user === null || !in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return null;
        }
        return $user;
    }
}
