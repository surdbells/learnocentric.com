<?php

declare(strict_types=1);

namespace App\Application\Actions\Assessment;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\PortfolioEntry;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Portfolio evidence for the Competency Transfer track. Students submit
 * evidence against a topic; staff review it qualitatively (a competency
 * rating, not a mark), so it never mixes into academic scores.
 */
final class PortfolioAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly \App\Service\NotificationService $notify,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return match (strtoupper($request->getMethod())) {
            'POST' => $this->create($request, $response),
            'PUT' => $this->update($request, $response),
            'DELETE' => $this->delete($request, $response),
            default => $this->staffList($request, $response),
        };
    }

    /** GET /assessment/portfolio, staff list of evidence, filterable. */
    private function staffList(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['title', 'status', 'created_at'], ['topic_id', 'subject_id', 'student_id', 'status', 'competency_rating'], 'created_at');

        $qb = $this->em->createQueryBuilder()->select('p')->from(PortfolioEntry::class, 'p')->join('p.topic', 't')->join('t.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(p.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        foreach (['status' => 'p.status', 'competency_rating' => 'p.competencyRating'] as $key => $field) {
            if (isset($query->filters[$key])) {
                $qb->andWhere("$field = :$key")->setParameter($key, $query->filters[$key]);
            }
        }
        if (isset($query->filters['topic_id'])) {
            $qb->andWhere('p.topic = :tid')->setParameter('tid', (int) $query->filters['topic_id']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('t.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['student_id'])) {
            $qb->andWhere('p.student = :stu')->setParameter('stu', (int) $query->filters['student_id']);
        }

        $mapper = static fn (PortfolioEntry $p) => $p->toArray();
        if (!$query->paginated) {
            $qb->orderBy('p.createdAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }
        $sortMap = ['title' => 'p.title', 'status' => 'p.status', 'created_at' => 'p.createdAt'];

        return Json::write($response, Paginator::paginate($qb, 'p', $query, $sortMap, $mapper));
    }

    /** GET /assessment/portfolio/mine, the current student's evidence. */
    public function mine(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $entries = $this->em->getRepository(PortfolioEntry::class)->findBy(['student' => $student], ['createdAt' => 'DESC']);

        return Json::write($response, ['data' => array_map(static fn (PortfolioEntry $p) => $p->toArray(), $entries), 'meta' => ['total' => count($entries)]]);
    }

    /**
     * GET /assessment/portfolio/tasks, the student's task-driven portfolio: every
     * topic that expects evidence (i.e. an assigned task) joined with the student's
     * latest submission, so the UI can show per-task status, brief and rating.
     */
    public function tasks(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)
            ->andWhere("t.portfolioEvidenceExpected IS NOT NULL AND t.portfolioEvidenceExpected <> ''")
            ->orderBy('t.weekNumber', 'ASC')->addOrderBy('t.title', 'ASC');
        if ($student->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $student->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('t.schoolClass IS NULL OR t.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('t.schoolClass IS NULL');
        }

        // Latest submission per topic, keyed by topic id, in one pass.
        $byTopic = [];
        foreach ($this->em->getRepository(PortfolioEntry::class)->findBy(['student' => $student], ['createdAt' => 'DESC']) as $entry) {
            /** @var PortfolioEntry $entry */
            $tid = $entry->getTopic()->getId();
            if (!isset($byTopic[$tid])) {
                $byTopic[$tid] = $entry;
            }
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $topic) {
            /** @var Topic $topic */
            $ta = $topic->toArray();
            $entry = $byTopic[$topic->getId()] ?? null;
            $status = $entry === null ? 'to_do' : $entry->getStatus();
            $rows[] = [
                'task_id' => $topic->getId(),
                'title' => $topic->getTitle(),
                'subject' => $topic->getSubject()->getName(),
                'week_number' => $ta['week_number'],
                'brief' => $ta['portfolio_evidence_expected'],
                'objective' => $ta['objective'],
                'competency_built' => $ta['competency_built'],
                'status' => $status,
                'entry_id' => $entry?->getId(),
                'competency_rating' => $entry?->toArray()['competency_rating'] ?? null,
                'reviewer_feedback' => $entry?->toArray()['reviewer_feedback'] ?? null,
                'reviewed_by' => $entry?->toArray()['reviewed_by'] ?? null,
                'evidence_url' => $entry?->toArray()['evidence_url'] ?? null,
                'submitted_at' => $entry?->toArray()['submitted_at'] ?? null,
            ];
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** GET /assessment/portfolio/topics, topics that expect evidence, for the student's classes. */
    public function topics(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        $qb = $this->em->createQueryBuilder()->select('t')->from(Topic::class, 't')->join('t.subject', 's')
            ->where('t.approvalStatus = :pub')->setParameter('pub', Lifecycle::PUBLISHED)->orderBy('t.title', 'ASC');
        if ($student->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $student->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('t.schoolClass IS NULL OR t.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        }

        $rows = array_map(static fn (Topic $t) => [
            'id' => $t->getId(),
            'title' => $t->getTitle(),
            'subject' => $t->getSubject()->getName(),
            'competency_expected' => $t->toArray()['portfolio_evidence_expected'],
            'competency_built' => $t->toArray()['competency_built'],
        ], $qb->getQuery()->getResult());

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    private function create(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['topic_id'] ?? 0));
        $title = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        if ($topic === null || $title === '' || $description === '') {
            return Json::error($response, 'A topic, a title and a description are required.', 422);
        }

        $entry = new PortfolioEntry($topic, $student, $title, $description);
        $entry->setEvidenceUrl(!empty($body['evidence_url']) ? (string) $body['evidence_url'] : null);
        $entry->setStatus(PortfolioEntry::SUBMITTED);
        $entry->setSubmittedAt(new DateTimeImmutable());
        $this->em->persist($entry);
        $this->em->flush();
        $this->audit->log('portfolio.submit', $student, 'PortfolioEntry', (string) $entry->getId(), null, ['topic_id' => $topic->getId()]);

        return Json::write($response, $entry->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $entry = $this->em->getRepository(PortfolioEntry::class)->find((int) ($body['id'] ?? 0));
        if ($entry === null) {
            return Json::error($response, 'Entry not found.', 404);
        }
        if ($entry->getStudent()->getId() !== $student->getId()) {
            return Json::error($response, 'You can only edit your own evidence.', 403);
        }
        if ($entry->getStatus() === PortfolioEntry::REVIEWED) {
            return Json::error($response, 'This evidence has been reviewed and can no longer be edited.', 409);
        }
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $entry->setTitle((string) $body['title']);
        }
        if (isset($body['description']) && trim((string) $body['description']) !== '') {
            $entry->setDescription((string) $body['description']);
        }
        if (array_key_exists('evidence_url', $body)) {
            $entry->setEvidenceUrl($body['evidence_url'] !== '' ? (string) $body['evidence_url'] : null);
        }
        $this->em->flush();

        return Json::write($response, $entry->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $entry = $this->em->getRepository(PortfolioEntry::class)->find((int) ($request->getQueryParams()['id'] ?? 0));
        if ($entry === null) {
            return Json::error($response, 'Entry not found.', 404);
        }
        if ($entry->getStudent()->getId() !== $student->getId()) {
            return Json::error($response, 'You can only delete your own evidence.', 403);
        }
        if ($entry->getStatus() === PortfolioEntry::REVIEWED) {
            return Json::error($response, 'Reviewed evidence cannot be deleted.', 409);
        }
        $this->em->remove($entry);
        $this->em->flush();

        return Json::write($response, ['deleted' => true, 'id' => $entry->getId()]);
    }

    /** POST /assessment/portfolio/{id}/review, staff records a competency rating + feedback. */
    public function review(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $entry = $this->em->getRepository(PortfolioEntry::class)->find((int) $args['id']);
        if ($entry === null) {
            return Json::error($response, 'Entry not found.', 404);
        }
        $body = (array) $request->getParsedBody();
        $rating = (string) ($body['competency_rating'] ?? '');
        if (!in_array($rating, PortfolioEntry::RATINGS, true)) {
            return Json::error($response, 'Choose a competency rating: ' . implode(', ', PortfolioEntry::RATINGS) . '.', 422);
        }
        $entry->setCompetencyRating($rating);
        $entry->setReviewerFeedback(isset($body['feedback']) && $body['feedback'] !== '' ? (string) $body['feedback'] : null);
        $entry->setReviewedBy($this->currentUser($request));
        $entry->setStatus(PortfolioEntry::REVIEWED);
        $entry->setReviewedAt(new DateTimeImmutable());
        $this->notify->notify(
            $entry->getStudent(),
            'portfolio',
            'Portfolio evidence reviewed: ' . $entry->getTitle(),
            'Your competency was rated "' . $rating . '".',
            '/student/academics/portfolio',
        );
        $this->em->flush();
        $this->audit->log('portfolio.review', $request->getAttribute('user'), 'PortfolioEntry', (string) $entry->getId(), null, ['rating' => $rating]);

        return Json::write($response, $entry->toArray());
    }

    /**
     * PUT /assessment/portfolio/tasks, staff assign (or update) the portfolio brief
     * on a topic. A topic with a non-empty brief becomes a portfolio task the topic's
     * learners see and submit evidence against. Unlike editing topic content, this is
     * allowed regardless of the topic's approval status (it's an assignment, not a
     * content change needing re-approval), but the task only surfaces to learners
     * once the topic is published.
     */
    public function assignTask(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $user = $this->currentUser($request);
        $body = (array) $request->getParsedBody();
        $topic = $this->em->getRepository(Topic::class)->find((int) ($body['topic_id'] ?? 0));
        $brief = trim((string) ($body['portfolio_evidence_expected'] ?? $body['brief'] ?? ''));
        if ($topic === null) {
            return Json::error($response, 'Choose a valid topic.', 422);
        }
        if ($brief === '') {
            return Json::error($response, 'Describe the evidence the learner should submit.', 422);
        }
        $inst = $user->getInstitution();
        if ($inst !== null && $topic->getSubject()->getInstitution()->getId() !== $inst->getId()) {
            return Json::error($response, "You can only assign tasks for your own school's topics.", 403);
        }

        $topic->setPortfolioEvidenceExpected($brief);
        if (array_key_exists('competency_built', $body)) {
            $topic->setCompetencyBuilt($body['competency_built'] !== '' ? (string) $body['competency_built'] : null);
        }
        $this->em->flush();
        $this->audit->log('portfolio.assign_task', $user, 'Topic', (string) $topic->getId(), null, ['brief' => $brief]);

        return Json::write($response, [
            'topic_id' => $topic->getId(),
            'title' => $topic->getTitle(),
            'subject' => $topic->getSubject()->getName(),
            'status' => $topic->getApprovalStatus(),
            'published' => $topic->getApprovalStatus() === Lifecycle::PUBLISHED,
            'portfolio_evidence_expected' => $topic->getPortfolioEvidenceExpected(),
        ]);
    }

    // --- helpers ---

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }

    /** @return int[] */
    private function studentClassIds(User $student): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Enrollment::class)->findBy(['student' => $student]) as $enrollment) {
            $ids[] = $enrollment->getSchoolClass()->getId();
        }
        return array_values(array_unique($ids));
    }

    private function staffGuard(Request $request, Response $response): ?Response
    {
        $user = $this->currentUser($request);
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only teachers and administrators can do that.', 403);
        }
        return null;
    }
}
