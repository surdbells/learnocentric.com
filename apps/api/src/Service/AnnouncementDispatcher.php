<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Announcement;
use App\Domain\Entity\Enrollment;
use App\Domain\Entity\GuardianLink;
use App\Domain\Entity\Institution;
use App\Domain\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delivers announcements.
 *
 *  - send(): in-app notifications to the audience (plus linked parents when
 *    "parent copy" is ticked); marks the announcement sent.
 *  - sendDue(): sends scheduled announcements whose time has come. Called by
 *    the announcements:dispatch command (cron) and, as a safety net, whenever
 *    someone in the school opens announcements or notifications.
 *  - emailPending(): delivers the email channel for sent announcements. Only
 *    run from the command, because emailing a whole school inside a web
 *    request would time out.
 */
class AnnouncementDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
    ) {
    }

    /** Send now (in-app). The caller flushes. Returns the number of recipients. */
    public function send(Announcement $a): int
    {
        $recipients = $this->recipients($a);
        $preview = mb_strimwidth(trim(strip_tags($a->getBody())), 0, 140, '…');
        foreach ($recipients as $r) {
            $this->notifications->notify($r, 'announcement', $a->getTitle(), $preview, '/notifications', false);
        }
        $a->setStatus(Announcement::SENT);
        $a->setSentAt(new DateTimeImmutable());
        $a->setRecipientCount(count($recipients));
        if (!$a->wantsEmail()) {
            $a->markEmailed(0); // nothing queued for the email dispatcher
        }

        return count($recipients);
    }

    /** Send every scheduled announcement that is due (optionally for one school). Flushes. */
    public function sendDue(?Institution $institution = null): int
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Announcement::class, 'a')
            ->where('a.status = :s')->andWhere('a.scheduledAt <= :now')
            ->setParameter('s', Announcement::SCHEDULED)->setParameter('now', new DateTimeImmutable());
        if ($institution !== null) {
            $qb->andWhere('a.institution = :i')->setParameter('i', $institution);
        }
        $due = $qb->getQuery()->getResult();
        $sent = 0;
        foreach ($due as $a) {
            /** @var Announcement $a */
            if (trim($a->getBody()) === '') {
                $a->setStatus(Announcement::DRAFT); // nothing to send; hand it back to the author
                continue;
            }
            try {
                $this->send($a);
                $sent++;
            } catch (\Throwable $e) {
                // One bad announcement must never break the page (or the others) that triggered this.
                $a->setStatus(Announcement::DRAFT);
            }
        }
        if ($due !== []) {
            $this->em->flush();
        }

        return $sent;
    }

    /**
     * Email sent announcements that asked for the email channel and haven't
     * been emailed yet. Returns [announcements, emails accepted]. Flushes.
     *
     * @return array{0:int,1:int}
     */
    public function emailPending(int $limit = 20): array
    {
        if (!$this->notifications->emailAvailable()) {
            return [0, 0];
        }
        $pending = $this->em->createQueryBuilder()->select('a')->from(Announcement::class, 'a')
            ->where('a.status = :s')->andWhere('a.emailedAt IS NULL')
            ->setParameter('s', Announcement::SENT)->orderBy('a.sentAt', 'ASC')
            ->getQuery()->getResult();
        $done = 0;
        $emails = 0;
        foreach ($pending as $a) {
            /** @var Announcement $a */
            if (!$a->wantsEmail()) {
                $a->markEmailed(0);
                continue;
            }
            if ($done >= $limit) {
                break;
            }
            $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $a->getBody()))));
            $count = 0;
            foreach ($this->recipients($a) as $r) {
                if ($this->notifications->email($r, $a->getTitle(), $text)) {
                    $count++;
                }
            }
            $a->markEmailed($count);
            $this->em->flush();
            $done++;
            $emails += $count;
        }

        return [$done, $emails];
    }

    /**
     * The audience, de-duplicated; with "parent copy", the guardians linked to
     * any learner in it are added.
     *
     * @return User[]
     */
    public function recipients(Announcement $a): array
    {
        if ($a->getAudience() === 'class' && $a->getSchoolClass() !== null) {
            $users = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')
                ->where('u.id IN (SELECT IDENTITY(e.student) FROM ' . Enrollment::class . ' e WHERE e.schoolClass = :c)')
                ->andWhere('u.status = :active')
                ->setParameter('c', $a->getSchoolClass())->setParameter('active', 'active')
                ->getQuery()->getResult();
        } else {
            $users = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
                ->where('u.institution = :i')->andWhere('r.effectiveCode IN (:roles)')->andWhere('u.status = :active')
                ->setParameter('i', $a->getInstitution())->setParameter('roles', Announcement::rolesForAudience($a->getAudience()))
                ->setParameter('active', 'active')
                ->getQuery()->getResult();
        }

        $byId = [];
        foreach ($users as $u) {
            /** @var User $u */
            $byId[$u->getId()] = $u;
        }
        // "Parent communication" in the school's notification defaults is the master switch for parent copies.
        $parentsAllowed = ($a->getInstitution()->getSettings()['notifications']['parent_communication'] ?? true) !== false;
        if ($a->wantsParentCopy() && $parentsAllowed) {
            $learners = array_values(array_filter($byId, static fn (User $u) => $u->getRole()->effectiveCode() === 'student'));
            if ($learners !== []) {
                $links = $this->em->createQueryBuilder()->select('l', 'g')->from(GuardianLink::class, 'l')->join('l.guardian', 'g')
                    ->where('l.student IN (:s)')->setParameter('s', $learners)->getQuery()->getResult();
                foreach ($links as $l) {
                    /** @var GuardianLink $l */
                    $g = $l->getGuardian();
                    if ($g->getStatus() === 'active') {
                        $byId[$g->getId()] = $g;
                    }
                }
            }
        }

        return array_values($byId);
    }
}
