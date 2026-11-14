<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Application\Support\Portal;
use App\Domain\Entity\Role;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\User;
use App\Service\NotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells the people who pay, at each point the subscription changes state:
 *
 *   7 days before the last paid day   reminder to renew (or that it will end, if renewal was stopped)
 *   the day after it                  payment is due; grace has started
 *   day 8                             tutor and learner access is suspended
 *   14 days before day 90             the retention period is about to end
 *   after day 90                      the platform owner is told the data is eligible for deletion
 *
 * Each message goes out once for a given last paid day. Nothing here changes
 * access or deletes anything: it only reports. Run from the scheduled command.
 */
final class SubscriptionNotices
{
    /** How long before the retention period ends the organisation is warned. */
    private const RETENTION_WARNING_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notify,
    ) {
    }

    /** @return int how many subscriptions had a message sent */
    public function sendDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $touched = 0;
        foreach ($this->em->getRepository(Subscription::class)->findBy(['cancelled' => false]) as $sub) {
            /** @var Subscription $sub */
            $sub->rollOver($now);
            if ($this->process($sub, $now)) {
                $touched++;
            }
        }
        $this->em->flush();

        return $touched;
    }

    private function process(Subscription $sub, DateTimeImmutable $now): bool
    {
        $status = $sub->status($now);
        $plan = $sub->getPlan()->getName();
        $lastDay = $sub->paidUntil()->format('j F Y');
        $keptUntil = $sub->retentionUntil()->format('j F Y');
        $school = $sub->getInstitution()->getName();

        if ($status === Subscription::ACTIVE) {
            if ($sub->hasPaidAhead() || $now < $sub->paidUntil()->modify('-' . Subscription::REMINDER_DAYS . ' days')) {
                return false;
            }
            if ($sub->isCancelAtPeriodEnd()) {
                return $this->once($sub, 'ending', $now, 'Your subscription ends on ' . $lastDay, sprintf(
                    'Renewal of the %s plan was stopped, so access for tutors and learners ends on %s. Your files and learning records are kept until %s, and an administrator can ask for an export before then. To continue, renew from Billing.',
                    $plan, $lastDay, $keptUntil,
                ));
            }

            return $this->once($sub, 'renewal_reminder', $now, 'Your subscription is due for renewal on ' . $lastDay, sprintf(
                'The %s plan for %s is paid to %s. Renew from Billing by then to keep access without interruption.',
                $plan, $school, $lastDay,
            ));
        }

        if ($status === Subscription::GRACE) {
            return $this->once($sub, 'grace', $now, 'Payment is due for your subscription', sprintf(
                'The %s plan was paid to %s. Access continues for now, but tutors and learners will be suspended after %s unless the subscription is renewed.',
                $plan, $lastDay, $sub->graceUntil()->format('j F Y'),
            ));
        }

        if ($status !== Subscription::EXPIRED) {
            return false;
        }

        if ($sub->isDeletionEligible($now)) {
            // The customer was warned beforehand; now the platform owner decides. Nothing is deleted here.
            if ($sub->noticeSent('retention_ended')) {
                return false;
            }
            foreach ($this->platformOwners() as $owner) {
                $this->notify->notify($owner, 'billing', $school . ': retention period has ended', sprintf(
                    '%s was last paid to %s and its 90-day retention period ended on %s. Its files and learning records are now eligible for deletion. Nothing has been deleted.',
                    $school, $lastDay, $keptUntil,
                ), '/super-admin/billing', false);
            }
            $sub->markNotice('retention_ended', $now);

            return true;
        }

        $sent = $this->once($sub, 'suspended', $now, 'Tutor and learner access is suspended', sprintf(
            'The %s plan was paid to %s and has not been renewed, so tutors and learners can no longer use %s. Renew from Billing to restore access at once. Your files and learning records are kept until %s; an administrator can ask for an export before then.',
            $plan, $lastDay, $school, $keptUntil,
        ));

        if ($now >= $sub->retentionUntil()->modify('-' . self::RETENTION_WARNING_DAYS . ' days')) {
            $sent = $this->once($sub, 'retention_warning', $now, 'Your data will become eligible for deletion on ' . $keptUntil, sprintf(
                'The retention period for %s ends on %s. After that date its files and learning records may be deleted and cannot be recovered. Renew, or ask for an export, before then.',
                $school, $keptUntil,
            )) || $sent;
        }

        return $sent;
    }

    /** Send one message to the organisation's administrators, once for the current last paid day. */
    private function once(Subscription $sub, string $key, DateTimeImmutable $now, string $title, string $message): bool
    {
        if ($sub->noticeSent($key)) {
            return false;
        }
        foreach ($this->payers($sub) as $admin) {
            $this->notify->notify($admin, 'billing', $title, $message, Portal::link($admin, '/management/billing'));
        }
        $sub->markNotice($key, $now);

        return true;
    }

    /** @return User[] the active administrators who manage billing for the organisation */
    private function payers(Subscription $sub): array
    {
        return $this->em->createQueryBuilder()->select('u')
            ->from(User::class, 'u')->join('u.role', 'r')
            ->where('u.institution = :inst')->andWhere('u.status = :active')
            ->andWhere('r.effectiveCode IN (:roles)')
            ->setParameter('inst', $sub->getInstitution())->setParameter('active', 'active')
            ->setParameter('roles', [Role::SCHOOL_ADMIN, Role::TUTOR_ADMIN])
            ->getQuery()->getResult();
    }

    /** @return User[] */
    private function platformOwners(): array
    {
        return $this->em->createQueryBuilder()->select('u')
            ->from(User::class, 'u')->join('u.role', 'r')
            ->where('r.effectiveCode = :role')->andWhere('u.status = :active')
            ->setParameter('role', Role::SUPER_ADMIN)->setParameter('active', 'active')
            ->getQuery()->getResult();
    }
}
