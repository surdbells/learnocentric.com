<?php

declare(strict_types=1);

namespace App\Application\Actions\Messaging;

use App\Application\Support\Json;
use App\Domain\Entity\Announcement;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Message;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/messaging — governed direct messaging + school announcements.
 * Messaging is scoped to a single institution and constrained by role pairs:
 * a student may reach teachers and admins but not other students or parents.
 */
final class MessagingAction
{
    /** Which roles each role is allowed to message. */
    private const PAIRS = [
        'school_admin' => ['school_admin', 'tutor_admin', 'teacher', 'student', 'parent'],
        'tutor_admin' => ['school_admin', 'tutor_admin', 'teacher', 'student', 'parent'],
        'teacher' => ['school_admin', 'tutor_admin', 'teacher', 'student', 'parent'],
        'student' => ['school_admin', 'tutor_admin', 'teacher'],
        'parent' => ['school_admin', 'tutor_admin', 'teacher'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /messaging/contacts — everyone the caller may start a conversation with. */
    public function contacts(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::write($response, []);
        }
        $allowed = self::PAIRS[$user->getRole()->getCode()] ?? [];
        if ($allowed === []) {
            return Json::write($response, []);
        }
        $rows = $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')->andWhere('r.code IN (:roles)')->andWhere('u.id != :me')
            ->setParameter('inst', $institution)->setParameter('roles', $allowed)->setParameter('me', $user->getId())
            ->orderBy('u.firstName', 'ASC')
            ->getQuery()->getResult();

        return Json::write($response, array_map(fn (User $u) => $this->contactRow($u), $rows));
    }

    /** GET /messaging/conversations — one row per counterpart with last message + unread. */
    public function conversations(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $messages = $this->em->createQueryBuilder()
            ->select('m')->from(Message::class, 'm')
            ->where('m.sender = :me OR m.recipient = :me')->setParameter('me', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()->getResult();

        $threads = [];
        foreach ($messages as $m) {
            /** @var Message $m */
            $other = $m->getSender()->getId() === $user->getId() ? $m->getRecipient() : $m->getSender();
            $key = $other->getId();
            if (!isset($threads[$key])) {
                $threads[$key] = ['contact' => $this->contactRow($other), 'last' => $m->toArray($user->getId()), 'unread' => 0];
            }
            if (!$m->isRead() && $m->getRecipient()->getId() === $user->getId()) {
                $threads[$key]['unread']++;
            }
        }

        return Json::write($response, array_values($threads));
    }

    /** GET /messaging/conversations/{id} — the thread with one user; marks incoming read. */
    public function thread(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $other = $this->em->getRepository(User::class)->find((int) $args['id']);
        if ($other === null || $other->getInstitution() !== $user->getInstitution()) {
            return Json::error($response, 'Contact not found.', 404);
        }

        $messages = $this->em->createQueryBuilder()
            ->select('m')->from(Message::class, 'm')
            ->where('(m.sender = :me AND m.recipient = :other) OR (m.sender = :other AND m.recipient = :me)')
            ->setParameter('me', $user)->setParameter('other', $other)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()->getResult();

        $changed = false;
        foreach ($messages as $m) {
            if (!$m->isRead() && $m->getRecipient()->getId() === $user->getId()) {
                $m->markRead();
                $changed = true;
            }
        }
        if ($changed) {
            $this->em->flush();
        }

        return Json::write($response, [
            'contact' => $this->contactRow($other),
            'messages' => array_map(fn (Message $m) => $m->toArray($user->getId()), $messages),
        ]);
    }

    /** POST /messaging/messages — send {recipient_id, body}. */
    public function send(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $text = trim((string) ($body['body'] ?? ''));
        $recipient = $this->em->getRepository(User::class)->find((int) ($body['recipient_id'] ?? 0));

        if ($text === '') {
            return Json::error($response, 'A message is required.', 422);
        }
        if ($recipient === null || $user->getInstitution() === null || $recipient->getInstitution() !== $user->getInstitution()) {
            return Json::error($response, 'Recipient not found in your institution.', 404);
        }
        if (!$this->canMessage($user, $recipient)) {
            return Json::error($response, 'You are not permitted to message this person.', 403);
        }

        $message = new Message($user->getInstitution(), $user, $recipient, $text);
        $this->em->persist($message);
        $this->em->flush();

        $this->notifications->notify(
            $recipient,
            'message.received',
            'New message from ' . trim($user->getFirstName() . ' ' . $user->getLastName()),
            mb_strimwidth($text, 0, 120, '…'),
            '/notifications',
            false, // in-app only; messaging isn't email-worthy per message
        );
        $this->audit->log('message.send', $user, 'Message', (string) $message->getId(), null, ['to' => $recipient->getId()]);

        return Json::write($response, $message->toArray($user->getId()), 201);
    }

    private const STAFF = ['school_admin', 'tutor_admin', 'teacher'];

    /**
     * GET /messaging/announcements — staff see the full institution log (with
     * optional ?audience/?category/?status/?q filters); learners/parents get
     * only sent announcements matching their audience.
     */
    public function announcements(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::write($response, []);
        }
        $role = $user->getRole()->getCode();
        $isStaff = in_array($role, self::STAFF, true);
        $all = $this->em->getRepository(Announcement::class)->findBy(['institution' => $institution], ['createdAt' => 'DESC']);

        if ($isStaff) {
            $q = $request->getQueryParams();
            $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
            $rows = array_filter($all, static function (Announcement $a) use ($q, $needle) {
                if (!empty($q['audience']) && $a->getAudience() !== $q['audience']) { return false; }
                if (!empty($q['category']) && $a->getCategory() !== $q['category']) { return false; }
                if (!empty($q['status']) && $a->getStatus() !== $q['status']) { return false; }
                if ($needle !== '' && !str_contains(mb_strtolower($a->getTitle()), $needle)) { return false; }
                return true;
            });
            return Json::write($response, array_map(static fn (Announcement $a) => $a->toArray(), array_values($rows)));
        }

        // Learner/parent feed: only sent, audience-matched.
        $visible = array_filter($all, static fn (Announcement $a) => $a->getStatus() === Announcement::SENT
            && in_array($role, Announcement::rolesForAudience($a->getAudience()), true));

        return Json::write($response, array_map(static fn (Announcement $a) => $a->toArray(), array_values($visible)));
    }

    /** GET /messaging/announcements/stats — Communication-hub KPIs + attention. */
    public function announcementStats(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $user->getInstitution();
        if ($institution === null || !in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Staff only.', 403);
        }
        $all = $this->em->getRepository(Announcement::class)->findBy(['institution' => $institution]);
        $sent = array_filter($all, static fn (Announcement $a) => $a->getStatus() === Announcement::SENT);
        $recipients = array_sum(array_map(static fn (Announcement $a) => $a->getRecipientCount(), $sent));
        $drafts = count(array_filter($all, static fn (Announcement $a) => $a->getStatus() === Announcement::DRAFT));
        $scheduled = count(array_filter($all, static fn (Announcement $a) => $a->getStatus() === Announcement::SCHEDULED));

        $messagesTotal = (int) $this->em->createQueryBuilder()->select('COUNT(m.id)')->from(Message::class, 'm')
            ->where('m.institution = :i')->setParameter('i', $institution)->getQuery()->getSingleScalarResult();

        return Json::write($response, [
            'announcements_sent' => count($sent),
            'recipients_reached' => $recipients,
            'messages_total' => $messagesTotal,
            'drafts' => $drafts,
            'scheduled' => $scheduled,
        ]);
    }

    /** POST /messaging/announcements — create (draft / schedule / send). */
    public function postAnnouncement(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Only staff can post announcements.', 403);
        }
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $text = trim((string) ($body['body'] ?? ''));
        $intent = (string) ($body['status'] ?? 'send'); // draft | send
        if ($title === '') {
            return Json::error($response, 'A title is required.', 422);
        }
        if ($intent !== 'draft' && $text === '') {
            return Json::error($response, 'A message is required to send.', 422);
        }

        $announcement = new Announcement($institution, $user, $title, $text, (string) ($body['audience'] ?? 'all'));
        $this->applyAnnouncementFields($announcement, $body);
        $this->em->persist($announcement);
        $this->finalizeAnnouncement($announcement, $intent, $body, $user);
        $this->em->flush();
        $this->audit->log('announcement.' . $announcement->getStatus(), $user, 'Announcement', (string) $announcement->getId(), null, ['audience' => $announcement->getAudience(), 'recipients' => $announcement->getRecipientCount()]);

        return Json::write($response, $announcement->toArray(), 201);
    }

    /** PUT /messaging/announcements/{id} — edit a draft (and optionally send it). */
    public function updateAnnouncement(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $announcement = $this->em->getRepository(Announcement::class)->find((int) $args['id']);
        if ($announcement === null || $announcement->getInstitution() !== $user->getInstitution()) {
            return Json::error($response, 'Announcement not found.', 404);
        }
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Staff only.', 403);
        }
        if ($announcement->getStatus() === Announcement::SENT) {
            return Json::error($response, 'A sent announcement cannot be edited.', 409);
        }
        $body = (array) $request->getParsedBody();
        if (isset($body['title'])) { $announcement->setTitle(trim((string) $body['title'])); }
        if (isset($body['body'])) { $announcement->setBody(trim((string) $body['body'])); }
        if (isset($body['audience'])) { $announcement->setAudience((string) $body['audience']); }
        $this->applyAnnouncementFields($announcement, $body);
        $this->finalizeAnnouncement($announcement, (string) ($body['status'] ?? 'draft'), $body, $user);
        $this->em->flush();

        return Json::write($response, $announcement->toArray());
    }

    /** DELETE /messaging/announcements/{id} — remove a draft/scheduled item. */
    public function deleteAnnouncement(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $announcement = $this->em->getRepository(Announcement::class)->find((int) $args['id']);
        if ($announcement === null || $announcement->getInstitution() !== $user->getInstitution()) {
            return Json::error($response, 'Announcement not found.', 404);
        }
        if (!in_array($user->getRole()->getCode(), self::STAFF, true)) {
            return Json::error($response, 'Staff only.', 403);
        }
        $this->em->remove($announcement);
        $this->em->flush();

        return Json::write($response, ['ok' => true]);
    }

    private function applyAnnouncementFields(Announcement $a, array $body): void
    {
        if (isset($body['category'])) { $a->setCategory((string) $body['category']); }
        if (isset($body['priority'])) { $a->setPriority((string) $body['priority']); }
        if (array_key_exists('subject', $body)) { $a->setSubjectName($body['subject'] !== null ? (string) $body['subject'] : null); }
        if (array_key_exists('attachment_url', $body)) { $a->setAttachmentUrl($body['attachment_url'] !== '' ? (string) $body['attachment_url'] : null); }
        if (is_array($body['channels'] ?? null)) {
            $a->setChannels([
                'in_app' => (bool) ($body['channels']['in_app'] ?? true),
                'email' => (bool) ($body['channels']['email'] ?? false),
                'parent_copy' => (bool) ($body['channels']['parent_copy'] ?? false),
            ]);
        }
        if (array_key_exists('class_id', $body)) {
            $class = $body['class_id'] ? $this->em->getRepository(SchoolClass::class)->find((int) $body['class_id']) : null;
            $a->setSchoolClass($class && $class->getInstitution() === $a->getInstitution() ? $class : null);
        }
    }

    /** Set the lifecycle: draft, scheduled (future date), or send now. */
    private function finalizeAnnouncement(Announcement $a, string $intent, array $body, User $user): void
    {
        $scheduledAt = null;
        if (!empty($body['scheduled_at'])) {
            try { $scheduledAt = new DateTimeImmutable((string) $body['scheduled_at']); } catch (\Throwable) { $scheduledAt = null; }
        }

        if ($intent === 'draft') {
            $a->setStatus(Announcement::DRAFT);
            $a->setScheduledAt($scheduledAt);
            return;
        }
        if ($scheduledAt !== null && $scheduledAt > new DateTimeImmutable()) {
            $a->setStatus(Announcement::SCHEDULED);
            $a->setScheduledAt($scheduledAt);
            return;
        }
        // Send now — in-app notifications only. The email / parent-copy channels
        // are recorded on the announcement but dispatched by a mail queue (not
        // synchronously per recipient, which would block on the mailer).
        $recipients = $this->resolveRecipients($a);
        foreach ($recipients as $r) {
            $this->notifications->notify(
                $r,
                'announcement',
                $a->getTitle(),
                mb_strimwidth(strip_tags($a->getBody()), 0, 140, '…'),
                '/notifications',
                false,
            );
        }
        $a->setStatus(Announcement::SENT);
        $a->setSentAt(new DateTimeImmutable());
        $a->setRecipientCount(count($recipients));
    }

    /** @return User[] */
    private function resolveRecipients(Announcement $a): array
    {
        $institution = $a->getInstitution();
        if ($a->getAudience() === 'class' && $a->getSchoolClass() !== null) {
            $rows = $this->em->createQueryBuilder()->select('st')->from(Enrollment::class, 'e')->join('e.student', 'st')
                ->where('e.schoolClass = :c')->setParameter('c', $a->getSchoolClass())
                ->getQuery()->getResult();
            return $rows;
        }
        $roles = Announcement::rolesForAudience($a->getAudience());
        return $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :i')->andWhere('r.code IN (:roles)')
            ->setParameter('i', $institution)->setParameter('roles', $roles)
            ->getQuery()->getResult();
    }

    private function canMessage(User $a, User $b): bool
    {
        $allowed = self::PAIRS[$a->getRole()->getCode()] ?? [];
        return in_array($b->getRole()->getCode(), $allowed, true);
    }

    private function contactRow(User $u): array
    {
        return [
            'id' => $u->getId(),
            'name' => trim($u->getFirstName() . ' ' . $u->getLastName()),
            'role' => $u->getRole()->getCode(),
            'profile_image_url' => $u->getProfileImageUrl(),
        ];
    }
}
