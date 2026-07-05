<?php

declare(strict_types=1);

namespace App\Application\Actions\Messaging;

use App\Application\Support\Json;
use App\Domain\Entity\Announcement;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Message;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
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

    /** GET /messaging/announcements — the feed for the caller's audience. */
    public function announcements(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::write($response, []);
        }
        $role = $user->getRole()->getCode();
        $all = $this->em->getRepository(Announcement::class)->findBy(['institution' => $institution], ['createdAt' => 'DESC']);
        $visible = array_filter($all, static fn (Announcement $a) => in_array($role, Announcement::rolesForAudience($a->getAudience()), true));

        return Json::write($response, array_map(static fn (Announcement $a) => $a->toArray(), array_values($visible)));
    }

    /** POST /messaging/announcements — post one (staff only). */
    public function postAnnouncement(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!in_array($user->getRole()->getCode(), ['school_admin', 'tutor_admin', 'teacher'], true)) {
            return Json::error($response, 'Only staff can post announcements.', 403);
        }
        $institution = $user->getInstitution();
        if ($institution === null) {
            return Json::error($response, 'No institution is linked to this account.', 404);
        }
        $body = (array) $request->getParsedBody();
        $title = trim((string) ($body['title'] ?? ''));
        $text = trim((string) ($body['body'] ?? ''));
        if ($title === '' || $text === '') {
            return Json::error($response, 'A title and message are required.', 422);
        }

        $announcement = new Announcement($institution, $user, $title, $text, (string) ($body['audience'] ?? 'all'));
        $this->em->persist($announcement);
        $this->em->flush();
        $this->audit->log('announcement.post', $user, 'Announcement', (string) $announcement->getId(), null, $announcement->toArray());

        return Json::write($response, $announcement->toArray(), 201);
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
