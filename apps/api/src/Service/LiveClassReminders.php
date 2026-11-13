<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Support\Portal;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Institution;
use App\Domain\Entity\LiveClass;
use App\Domain\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Starts soon" reminders for live classes: the host and the learners
 * enrolled in the class get one in-app notification (and email, if they
 * allow it) about 15 minutes before the start. People who switched
 * "Live Class Reminders" off in their settings are skipped by
 * NotificationService. Run from announcements:dispatch (cron) and, as a
 * safety net, whenever someone in the school opens their notifications.
 */
class LiveClassReminders
{
    private const WINDOW = '+20 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
    ) {
    }

    /** Send reminders for classes starting within the window. Flushes. Returns classes reminded. */
    public function sendDue(?Institution $institution = null): int
    {
        $now = new DateTimeImmutable();
        $qb = $this->em->createQueryBuilder()->select('lc')->from(LiveClass::class, 'lc')->join('lc.subject', 's')
            ->where('lc.status = :st')->andWhere('lc.remindedAt IS NULL')
            ->andWhere('lc.scheduledAt > :now')->andWhere('lc.scheduledAt <= :soon')
            ->setParameter('st', LiveClass::SCHEDULED)->setParameter('now', $now)->setParameter('soon', $now->modify(self::WINDOW));
        if ($institution !== null) {
            $qb->andWhere('s.institution = :i')->setParameter('i', $institution);
        }
        $due = $qb->getQuery()->getResult();
        foreach ($due as $lc) {
            /** @var LiveClass $lc */
            $lc->markReminded();
            try {
                foreach ($this->audience($lc) as $user) {
                    $this->notifications->notify(
                        $user,
                        'live_class',
                        'Live class starting soon: ' . $lc->getTitle(),
                        'Your live class starts in about ' . max(1, (int) ceil(($lc->getScheduledAt()->getTimestamp() - $now->getTimestamp()) / 60)) . ' minutes.',
                        Portal::link($user, '/academics/live-classes'),
                    );
                }
            } catch (\Throwable $e) {
                // never let a reminder break the request that triggered it
            }
        }
        if ($due !== []) {
            $this->em->flush();
        }

        return count($due);
    }

    /** @return User[] the host plus the learners enrolled in the class */
    private function audience(LiveClass $lc): array
    {
        $byId = [];
        if ($lc->getHost() !== null) {
            $byId[$lc->getHost()->getId()] = $lc->getHost();
        }
        if ($lc->getSchoolClass() !== null) {
            $learners = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')
                ->where('u.id IN (SELECT IDENTITY(e.student) FROM ' . Enrollment::class . ' e WHERE e.schoolClass = :c)')
                ->andWhere('u.status = :active')
                ->setParameter('c', $lc->getSchoolClass())->setParameter('active', 'active')
                ->getQuery()->getResult();
            foreach ($learners as $u) {
                /** @var User $u */
                $byId[$u->getId()] = $u;
            }
        }

        return array_values($byId);
    }
}
