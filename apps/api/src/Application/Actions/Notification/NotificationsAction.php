<?php

declare(strict_types=1);

namespace App\Application\Actions\Notification;

use App\Application\Support\Json;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The current user's notification inbox. */
final class NotificationsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\AnnouncementDispatcher $dispatcher,
        private readonly \App\Service\LiveClassReminders $reminders,
    ) {
    }

    /** GET /notifications, recent notifications + unread count (?unread=1 to filter). */
    public function mine(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) ($params['limit'] ?? 30)));

        // Scheduled announcements that have come due go out now, so they reach
        // this inbox even where the cron job isn't set up.
        if ($user->getInstitution() !== null) {
            $this->dispatcher->sendDue($user->getInstitution());
            $this->reminders->sendDue($user->getInstitution());
        }

        $qb = $this->em->createQueryBuilder()->select('n')->from(Notification::class, 'n')
            ->where('n.user = :u')->setParameter('u', $user)
            ->orderBy('n.createdAt', 'DESC')->setMaxResults($limit);
        if (($params['unread'] ?? '') === '1') {
            $qb->andWhere('n.read = false');
        }
        $rows = $qb->getQuery()->getResult();

        $unread = (int) $this->em->createQueryBuilder()->select('COUNT(n.id)')->from(Notification::class, 'n')
            ->where('n.user = :u')->andWhere('n.read = false')->setParameter('u', $user)
            ->getQuery()->getSingleScalarResult();

        return Json::write($response, [
            'data' => array_map(static fn (Notification $n) => $n->toArray(), $rows),
            'meta' => ['unread' => $unread],
        ]);
    }

    /** POST /notifications/{id}/read, mark one read. */
    public function read(Request $request, Response $response, array $args): Response
    {
        $user = $this->currentUser($request);
        $notification = $this->em->getRepository(Notification::class)->find((int) $args['id']);
        if ($notification === null || $notification->getUser()->getId() !== $user->getId()) {
            return Json::error($response, 'Notification not found.', 404);
        }
        if (!$notification->isRead()) {
            $notification->setRead(true);
            $notification->setReadAt(new DateTimeImmutable());
            $this->em->flush();
        }

        return Json::write($response, $notification->toArray());
    }

    /** POST /notifications/read-all, mark every notification read. */
    public function readAll(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $count = $this->em->createQueryBuilder()->update(Notification::class, 'n')
            ->set('n.read', ':read')->set('n.readAt', ':now')
            ->where('n.user = :u')->andWhere('n.read = false')
            ->setParameter('read', true)->setParameter('now', new DateTimeImmutable())->setParameter('u', $user)
            ->getQuery()->execute();

        return Json::write($response, ['marked_read' => $count]);
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        return $user;
    }
}
