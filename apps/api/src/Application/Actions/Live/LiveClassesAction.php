<?php

declare(strict_types=1);

namespace App\Application\Actions\Live;

use App\Application\Actions\School\ResolvesInstitution;
use App\Application\Support\Json;
use App\Application\Support\ListQuery;
use App\Application\Support\Paginator;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\LiveClassAttendance;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\Subject;
use App\Domain\Entity\Topic;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\Video\DailyClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/live-classes — scheduling, running, joining and attendance for Daily.co classes. */
final class LiveClassesAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly DailyClient $daily,
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

    /** GET /live-classes — staff schedule. */
    private function list(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);
        $query = ListQuery::fromRequest($request, ['title', 'scheduled_at', 'status'], ['subject_id', 'class_id', 'status'], 'scheduled_at');

        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        if ($query->q !== '') {
            $qb->andWhere('LOWER(lc.title) LIKE :q')->setParameter('q', '%' . strtolower($query->q) . '%');
        }
        if (isset($query->filters['status'])) {
            $qb->andWhere('lc.status = :st')->setParameter('st', $query->filters['status']);
        }
        if (isset($query->filters['subject_id'])) {
            $qb->andWhere('lc.subject = :sid')->setParameter('sid', (int) $query->filters['subject_id']);
        }
        if (isset($query->filters['class_id'])) {
            $qb->andWhere('lc.schoolClass = :cid')->setParameter('cid', (int) $query->filters['class_id']);
        }

        $mapper = static fn (LiveClass $lc) => $lc->toArray();
        if (!$query->paginated) {
            $qb->orderBy('lc.scheduledAt', 'DESC');
            return Json::write($response, array_map($mapper, $qb->getQuery()->getResult()));
        }
        $sortMap = ['title' => 'lc.title', 'scheduled_at' => 'lc.scheduledAt', 'status' => 'lc.status'];

        return Json::write($response, Paginator::paginate($qb, 'lc', $query, $sortMap, $mapper));
    }

    private function create(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $subject = $this->em->getRepository(Subject::class)->find((int) ($body['subject_id'] ?? 0));
        $title = trim((string) ($body['title'] ?? ''));
        $when = $this->parseDate($body['scheduled_at'] ?? null);
        if ($subject === null || $title === '' || $when === null) {
            return Json::error($response, 'A subject, title and scheduled_at are required.', 422);
        }

        $lc = new LiveClass($subject, $title, $when);
        $lc->setHost($this->currentUser($request));
        $lc->setDurationMinutes((int) ($body['duration_minutes'] ?? 45));
        $this->applyRelations($lc, $body);
        $this->provisionRoom($lc);
        $this->em->persist($lc);
        $this->em->flush();
        $this->audit->log('liveclass.create', $request->getAttribute('user'), 'LiveClass', (string) $lc->getId(), null, $lc->toArray());

        return Json::write($response, $lc->toArray(), 201);
    }

    private function update(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        $lc = $this->em->getRepository(LiveClass::class)->find((int) ($body['id'] ?? 0));
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        if ($lc->getStatus() === LiveClass::ENDED) {
            return Json::error($response, 'This class has ended and can no longer be edited.', 409);
        }
        if (isset($body['title']) && trim((string) $body['title']) !== '') {
            $lc->setTitle((string) $body['title']);
        }
        if (isset($body['scheduled_at']) && ($when = $this->parseDate($body['scheduled_at'])) !== null) {
            $lc->setScheduledAt($when);
        }
        if (isset($body['duration_minutes'])) {
            $lc->setDurationMinutes((int) $body['duration_minutes']);
        }
        $this->applyRelations($lc, $body);
        $this->em->flush();

        return Json::write($response, $lc->toArray());
    }

    private function delete(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $lc = $this->em->getRepository(LiveClass::class)->find($id);
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        $this->em->remove($lc);
        $this->em->flush();
        $this->audit->log('liveclass.delete', $request->getAttribute('user'), 'LiveClass', (string) $id, null, null);

        return Json::write($response, ['deleted' => true, 'id' => $id]);
    }

    /** POST /live-classes/{id}/start | /end — host changes the session state. */
    public function start(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, (int) $args['id'], LiveClass::LIVE);
    }

    public function end(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, (int) $args['id'], LiveClass::ENDED);
    }

    private function setStatus(Request $request, Response $response, int $id, string $status): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $lc = $this->em->getRepository(LiveClass::class)->find($id);
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        $lc->setStatus($status);
        $this->em->flush();
        $this->audit->log('liveclass.' . $status, $request->getAttribute('user'), 'LiveClass', (string) $id, null, ['status' => $status]);

        return Json::write($response, $lc->toArray());
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
        foreach ($this->em->getRepository(LiveClass::class)->findBy(['id' => $ids]) as $lc) {
            $this->em->remove($lc);
            $count++;
        }
        $this->em->flush();

        return Json::write($response, ['deleted' => $count]);
    }

    /** GET /live-classes/upcoming — scheduled/live classes for the student's classes. */
    public function upcoming(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->where('lc.status IN (:open)')->setParameter('open', [LiveClass::SCHEDULED, LiveClass::LIVE])
            ->orderBy('lc.scheduledAt', 'ASC');
        if ($student->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $student->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('lc.schoolClass IS NULL OR lc.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('lc.schoolClass IS NULL');
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $lc) {
            /** @var LiveClass $lc */
            $joined = $this->em->getRepository(LiveClassAttendance::class)->findOneBy(['liveClass' => $lc, 'student' => $student]);
            $rows[] = $lc->toArray() + ['joined' => $joined !== null];
        }

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /** POST /live-classes/{id}/join — student joins; records attendance, returns the room URL. */
    public function join(Request $request, Response $response, array $args): Response
    {
        $student = $this->currentUser($request);
        $lc = $this->em->getRepository(LiveClass::class)->find((int) $args['id']);
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        if (!$lc->isJoinable()) {
            return Json::error($response, 'This class is not open to join.', 422);
        }
        $existing = $this->em->getRepository(LiveClassAttendance::class)->findOneBy(['liveClass' => $lc, 'student' => $student]);
        if ($existing === null) {
            $this->em->persist(new LiveClassAttendance($lc, $student, new DateTimeImmutable()));
            $this->em->flush();
            $this->audit->log('liveclass.join', $student, 'LiveClass', (string) $lc->getId(), null, null);
        }

        return Json::write($response, ['room_url' => $lc->getRoomUrl(), 'title' => $lc->getTitle(), 'status' => $lc->getStatus()]);
    }

    /** GET /live-classes/{id}/attendance — staff view of who joined. */
    public function attendance(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $lc = $this->em->getRepository(LiveClass::class)->find((int) $args['id']);
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        $records = $this->em->getRepository(LiveClassAttendance::class)->findBy(['liveClass' => $lc], ['joinedAt' => 'ASC']);
        $enrolled = $lc->getSchoolClass() !== null
            ? (int) $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $lc->getSchoolClass()])
            : null;

        return Json::write($response, [
            'live_class' => $lc->toArray(),
            'summary' => ['attended' => count($records), 'enrolled' => $enrolled],
            'data' => array_map(static fn (LiveClassAttendance $a) => $a->toArray(), $records),
        ]);
    }

    // --- helpers ---

    private function provisionRoom(LiveClass $lc): void
    {
        $name = 'learno-' . substr(md5($lc->getTitle() . microtime(true)), 0, 12);
        if ($this->daily->isConfigured()) {
            try {
                $exp = $lc->getScheduledAt()->modify('+' . ($lc->getDurationMinutes() + 120) . ' minutes')->getTimestamp();
                $room = $this->daily->createRoom($name, ['exp' => $exp, 'enable_chat' => true]);
                $lc->setRoomName($room['name'] ?? $name);
                $lc->setRoomUrl($room['url'] ?? ('https://learnocentric.daily.co/' . $name));
                return;
            } catch (Throwable $e) {
                // fall through to a placeholder room so scheduling still works without Daily configured
            }
        }
        $lc->setRoomName($name);
        $lc->setRoomUrl('https://learnocentric.daily.co/' . $name);
    }

    private function applyRelations(LiveClass $lc, array $body): void
    {
        if (array_key_exists('class_id', $body)) {
            $lc->setSchoolClass(!empty($body['class_id']) ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null);
        }
        if (array_key_exists('topic_id', $body)) {
            $lc->setTopic(!empty($body['topic_id']) ? $this->em->getRepository(Topic::class)->find((int) $body['topic_id']) : null);
        }
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    }

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
