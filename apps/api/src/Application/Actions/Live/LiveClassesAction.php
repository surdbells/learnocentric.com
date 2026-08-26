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
use App\Service\Video\AgoraTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/** /backend/live-classes — scheduling, running, joining and attendance for Agora live classes. */
final class LiveClassesAction
{
    use ResolvesInstitution;

    private const STAFF = ['teacher', 'academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly AgoraTokenService $agora,
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
        if ($subject === null || !$this->canActWithin($request, $subject->getInstitution()) || $title === '' || $when === null) {
            return Json::error($response, 'A subject, title and scheduled_at are required.', 422);
        }

        $lc = new LiveClass($subject, $title, $when);
        $lc->setHost($this->currentUser($request));
        $lc->setDurationMinutes((int) ($body['duration_minutes'] ?? 45));
        $this->applyRelations($lc, $body);
        $this->provisionChannel($lc);
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
        if ($lc === null || !$this->canActWithin($request, $lc->getSubject()->getInstitution())) {
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
        if ($lc === null || !$this->canActWithin($request, $lc->getSubject()->getInstitution())) {
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
        if ($lc === null || !$this->canActWithin($request, $lc->getSubject()->getInstitution())) {
            return Json::error($response, 'Live class not found.', 404);
        }
        // Assign a fresh channel as the class goes live so each session is a
        // clean channel (no stragglers from a prior run of the same class).
        if ($status === LiveClass::LIVE) {
            $this->provisionChannel($lc);
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

    /** GET /live-classes/board — the learner Live Classes page: next, upcoming, past, today. */
    public function board(Request $request, Response $response): Response
    {
        $student = $this->currentUser($request);
        $classIds = $this->studentClassIds($student);

        // Upcoming (scheduled or live), soonest first.
        $upcomingRows = $this->visibleQb($student, $classIds)
            ->andWhere('lc.status IN (:open)')->setParameter('open', [LiveClass::SCHEDULED, LiveClass::LIVE])
            ->orderBy('lc.scheduledAt', 'ASC')->getQuery()->getResult();
        $upcoming = [];
        foreach ($upcomingRows as $lc) {
            /** @var LiveClass $lc */
            $upcoming[] = $lc->toArray() + ['joined' => $this->attended($lc, $student)];
        }

        // Past (ended), most recent first.
        $pastRows = $this->visibleQb($student, $classIds)
            ->andWhere('lc.status = :ended')->setParameter('ended', LiveClass::ENDED)
            ->orderBy('lc.scheduledAt', 'DESC')->setMaxResults(12)->getQuery()->getResult();
        $past = [];
        foreach ($pastRows as $lc) {
            /** @var LiveClass $lc */
            $past[] = $lc->toArray() + ['attended' => $this->attended($lc, $student)];
        }

        // Today's schedule (any status), in time order.
        $start = new DateTimeImmutable('today 00:00:00');
        $todayRows = $this->visibleQb($student, $classIds)
            ->andWhere('lc.scheduledAt >= :s')->andWhere('lc.scheduledAt < :e')
            ->setParameter('s', $start)->setParameter('e', $start->modify('+1 day'))
            ->orderBy('lc.scheduledAt', 'ASC')->getQuery()->getResult();
        $today = array_map(static fn (LiveClass $lc) => $lc->toArray(), $todayRows);

        return Json::write($response, [
            'next' => $upcoming[0] ?? null,
            'upcoming' => $upcoming,
            'past' => $past,
            'today' => $today,
            'stats' => [
                'upcoming' => count($upcoming),
                'attended' => count(array_filter($past, static fn ($p) => $p['attended'])),
            ],
        ]);
    }

    /** GET /live-classes/staff-board — teacher/admin Live Classes page (KPIs, attendance, today). */
    public function staffBoard(Request $request, Response $response): Response
    {
        if (($guard = $this->staffGuard($request, $response)) !== null) {
            return $guard;
        }
        $institution = $this->resolveInstitution($request, $this->em);

        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->orderBy('lc.scheduledAt', 'DESC');
        if ($institution !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $institution);
        }
        $all = $qb->getQuery()->getResult();

        $weekStart = (new DateTimeImmutable('monday this week'))->setTime(0, 0);
        $weekEnd = $weekStart->modify('+7 days');
        $todayStart = new DateTimeImmutable('today 00:00');
        $todayEnd = $todayStart->modify('+1 day');

        $enrolledCache = [];
        $enrolledFor = function (LiveClass $lc) use (&$enrolledCache): ?int {
            $class = $lc->getSchoolClass();
            if ($class === null) {
                return null;
            }
            $cid = $class->getId();
            return $enrolledCache[$cid] ??= (int) $this->em->getRepository(Enrollment::class)->count(['schoolClass' => $cid]);
        };

        $rows = [];
        $today = [];
        $present = 0;
        $absent = 0;
        $sessions = 0;
        $attSum = 0.0;
        $attCount = 0;
        $thisWeek = 0;
        $completedWeek = 0;
        $upcomingToday = 0;

        foreach ($all as $lc) {
            /** @var LiveClass $lc */
            $attended = (int) $this->em->getRepository(LiveClassAttendance::class)->count(['liveClass' => $lc]);
            $enrolled = $enrolledFor($lc);
            $pct = ($enrolled !== null && $enrolled > 0) ? (int) round($attended / $enrolled * 100) : null;
            $row = $lc->toArray() + ['attended' => $attended, 'enrolled' => $enrolled, 'attendance_pct' => $pct];
            $rows[] = $row;

            $sched = $lc->getScheduledAt();
            $inWeek = $sched >= $weekStart && $sched < $weekEnd;
            if ($inWeek) {
                $thisWeek++;
            }
            if ($lc->getStatus() === LiveClass::ENDED) {
                if ($inWeek) {
                    $completedWeek++;
                }
                if ($enrolled !== null && $enrolled > 0) {
                    $present += $attended;
                    $absent += max(0, $enrolled - $attended);
                    $sessions++;
                    if ($pct !== null) {
                        $attSum += $pct;
                        $attCount++;
                    }
                }
            }
            if ($sched >= $todayStart && $sched < $todayEnd) {
                $today[] = $row;
                if (in_array($lc->getStatus(), [LiveClass::SCHEDULED, LiveClass::LIVE], true)) {
                    $upcomingToday++;
                }
            }
        }

        // Today's rail reads best in time order.
        usort($today, static fn ($a, $b) => strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']));

        return Json::write($response, [
            'kpis' => [
                'total' => count($all),
                'this_week' => $thisWeek,
                'upcoming_today' => $upcomingToday,
                'completed_this_week' => $completedWeek,
                'attendance_rate' => $attCount > 0 ? (int) round($attSum / $attCount) : null,
            ],
            'classes' => array_slice($rows, 0, 20),
            'today' => $today,
            'snapshot' => ['present' => $present, 'absent' => $absent, 'total_sessions' => $sessions],
        ]);
    }

    private function attended(LiveClass $lc, User $student): bool
    {
        return $this->em->getRepository(LiveClassAttendance::class)->findOneBy(['liveClass' => $lc, 'student' => $student]) !== null;
    }

    private function visibleQb(User $student, array $classIds): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's');
        if ($student->getInstitution() !== null) {
            $qb->andWhere('s.institution = :inst')->setParameter('inst', $student->getInstitution());
        }
        if (!empty($classIds)) {
            $qb->andWhere('lc.schoolClass IS NULL OR lc.schoolClass IN (:cids)')->setParameter('cids', $classIds);
        } else {
            $qb->andWhere('lc.schoolClass IS NULL');
        }
        return $qb;
    }

    /** POST /live-classes/{id}/join — student joins; records attendance, returns the room URL. */
    public function join(Request $request, Response $response, array $args): Response
    {
        $user = $this->currentUser($request);
        $lc = $this->em->getRepository(LiveClass::class)->find((int) $args['id']);
        if ($lc === null) {
            return Json::error($response, 'Live class not found.', 404);
        }
        $isStaff = in_array($user->getRole()->getCode(), self::STAFF, true);

        // A class is only joinable once it is live (a channel is assigned). Before
        // that a learner should wait for the host to go live.
        if ($lc->getStatus() !== LiveClass::LIVE) {
            return Json::error($response, 'This class hasn\'t started yet — please wait for the host to go live.', 422);
        }
        $channel = (string) $lc->getRoomName();
        if ($channel === '') {
            return Json::error($response, 'This class has no channel yet — ask the host to restart it.', 409);
        }
        if (!$this->agora->isConfigured()) {
            return Json::error($response, 'Live video is not configured on this server (missing Agora credentials).', 503);
        }

        // Learners get attendance recorded; staff (the host) do not.
        if (!$isStaff) {
            $existing = $this->em->getRepository(LiveClassAttendance::class)->findOneBy(['liveClass' => $lc, 'student' => $user]);
            if ($existing === null) {
                $this->em->persist(new LiveClassAttendance($lc, $user, new DateTimeImmutable()));
                $this->em->flush();
                $this->audit->log('liveclass.join', $user, 'LiveClass', (string) $lc->getId(), null, null);
            }
        }

        // Everyone in a class is a speaker (publisher): host and learners can both
        // share camera/mic. The uid ties the token to this user for the channel.
        $uid = (int) $user->getId();
        $ttl = max(300, $this->sessionExpiry($lc) - time());
        $token = $this->agora->rtcToken($channel, $uid, true, $ttl);

        return Json::write($response, [
            'app_id' => $this->agora->appId(),
            'channel' => $channel,
            'token' => $token,
            'uid' => $uid,
            'user_name' => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'is_host' => $isStaff,
            'title' => $lc->getTitle(),
            'status' => $lc->getStatus(),
        ]);
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

    /**
     * Assign the class an Agora channel. With Agora a channel is just a name —
     * there's no room resource to create — so this is a local slug; the RTC token
     * that authorises joining it is minted per-user at join time.
     */
    private function provisionChannel(LiveClass $lc): void
    {
        $channel = 'learno-' . substr(md5($lc->getTitle() . microtime(true)), 0, 16);
        $lc->setRoomName($channel);
        $lc->setRoomUrl(null); // Agora has no join URL; the client joins the channel by name.
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

    /**
     * Unix expiry for a class's RTC token: covers the session from the later of
     * now or the scheduled start, plus the duration and a grace buffer. Basing it
     * on `now` avoids past-dated expiries when a class starts late.
     */
    private function sessionExpiry(LiveClass $lc): int
    {
        $base = max($lc->getScheduledAt()->getTimestamp(), (new DateTimeImmutable())->getTimestamp());
        return $base + ($lc->getDurationMinutes() + 120) * 60;
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
