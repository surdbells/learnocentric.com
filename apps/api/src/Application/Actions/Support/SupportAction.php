<?php

declare(strict_types=1);

namespace App\Application\Actions\Support;

use App\Application\Support\Json;
use App\Domain\Entity\SupportMessage;
use App\Domain\Entity\SupportTicket;
use App\Domain\Entity\User;
use App\Service\AuditLogger;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * /backend/support/tickets — the support centre. Any user can open and follow
 * their own tickets; institution admins see their institution's; the platform
 * super admin sees and works everything (assign, reply, escalate, resolve).
 */
final class SupportAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET list — scoped by role. */
    public function list(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $repo = $this->em->getRepository(SupportTicket::class);

        if ($this->isPlatform($user)) {
            $tickets = $repo->findBy([], ['updatedAt' => 'DESC']);
        } elseif ($this->isInstitutionAdmin($user) && $user->getInstitution() !== null) {
            $tickets = $repo->findBy(['institution' => $user->getInstitution()], ['updatedAt' => 'DESC']);
        } else {
            $tickets = $repo->findBy(['createdBy' => $user], ['updatedAt' => 'DESC']);
        }

        return Json::write($response, array_map(static fn (SupportTicket $t) => $t->toArray(), $tickets));
    }

    /** POST — open a ticket. */
    public function create(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? $body['body'] ?? ''));
        if ($subject === '' || $message === '') {
            return Json::error($response, 'A subject and a message are required.', 422);
        }

        $ticket = new SupportTicket($subject, $user, $this->reference());
        $ticket->setCategory((string) ($body['category'] ?? 'technical'));
        $ticket->setPriority((string) ($body['priority'] ?? 'normal'));
        $ticket->addMessage(new SupportMessage($ticket, $user, $message, false));

        $this->em->persist($ticket);
        $this->em->flush();

        // Notify the platform team.
        foreach ($this->platformAdmins() as $admin) {
            $this->notifications->notify(
                $admin,
                'support.opened',
                'New support ticket: ' . $subject,
                $ticket->getInstitution()?->getName() . ' · ' . $ticket->toArray()['priority'],
                '/super-admin/management/support',
            );
        }
        $this->audit->log('support.open', $user, 'SupportTicket', (string) $ticket->getId(), null, $ticket->toArray());

        return Json::write($response, $ticket->toArray(true), 201);
    }

    /** GET one with its thread. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $ticket = $this->find($request, $response, (int) $args['id']);
        if ($ticket instanceof Response) {
            return $ticket;
        }
        return Json::write($response, $ticket->toArray(true));
    }

    /** POST reply — add a message to the thread. */
    public function reply(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $ticket = $this->find($request, $response, (int) $args['id']);
        if ($ticket instanceof Response) {
            return $ticket;
        }
        $text = trim((string) (((array) $request->getParsedBody())['body'] ?? ''));
        if ($text === '') {
            return Json::error($response, 'A message is required.', 422);
        }

        $isStaff = $this->isPlatform($user);
        $ticket->addMessage(new SupportMessage($ticket, $user, $text, $isStaff));
        if ($isStaff) {
            $ticket->markStaffResponded();
            // Let the requester know there's a reply.
            $this->notifications->notify(
                $ticket->getCreatedBy(),
                'support.reply',
                'Support replied to “' . $ticket->toArray()['subject'] . '”',
                $text,
                '/notifications',
            );
        }
        $this->em->flush();

        return Json::write($response, $ticket->toArray(true));
    }

    /** POST transition — status change / assign-to-me / escalate (platform only). */
    public function transition(Request $request, Response $response, array $args): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        if (!$this->isPlatform($user)) {
            return Json::error($response, 'Only the support team can update a ticket.', 403);
        }
        $ticket = $this->em->getRepository(SupportTicket::class)->find((int) $args['id']);
        if ($ticket === null) {
            return Json::error($response, 'Ticket not found.', 404);
        }
        $before = $ticket->toArray();
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'status');

        if ($action === 'escalate') {
            $ticket->escalate();
        } elseif ($action === 'assign') {
            $ticket->setAssignedTo($user);
            $ticket->markStaffResponded();
        } elseif (isset($body['status'])) {
            $ticket->setStatus((string) $body['status']);
            $notify = in_array($body['status'], [SupportTicket::RESOLVED, SupportTicket::CLOSED], true);
            if ($notify) {
                $this->notifications->notify(
                    $ticket->getCreatedBy(),
                    'support.' . $body['status'],
                    'Your ticket “' . $ticket->toArray()['subject'] . '” was ' . $body['status'],
                    null,
                    '/notifications',
                );
            }
        }
        $this->em->flush();
        $this->audit->log('support.transition', $user, 'SupportTicket', (string) $ticket->getId(), $before, $ticket->toArray());

        return Json::write($response, $ticket->toArray(true));
    }

    /** @return SupportTicket|Response the ticket if the caller may see it, else an error response */
    private function find(Request $request, Response $response, int $id): SupportTicket|Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $ticket = $this->em->getRepository(SupportTicket::class)->find($id);
        if ($ticket === null) {
            return Json::error($response, 'Ticket not found.', 404);
        }
        $sameInstitution = $ticket->getInstitution() !== null && $ticket->getInstitution() === $user->getInstitution();
        $mayView = $this->isPlatform($user)
            || $ticket->getCreatedBy()->getId() === $user->getId()
            || ($this->isInstitutionAdmin($user) && $sameInstitution);
        if (!$mayView) {
            return Json::error($response, 'You do not have access to that ticket.', 403);
        }
        return $ticket;
    }

    private function isPlatform(User $user): bool
    {
        return $user->getRole()->getCode() === 'super_admin';
    }

    private function isInstitutionAdmin(User $user): bool
    {
        return in_array($user->getRole()->getCode(), ['school_admin', 'tutor_admin'], true);
    }

    /** @return User[] */
    private function platformAdmins(): array
    {
        return $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->join('u.role', 'r')->where('r.code = :c')->setParameter('c', 'super_admin')
            ->getQuery()->getResult();
    }

    private function reference(): string
    {
        return 'LEARNO-TKT-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
