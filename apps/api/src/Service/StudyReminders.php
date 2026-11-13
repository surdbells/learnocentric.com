<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\AssessmentAttempt;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\TopicProgress;
use App\Domain\Entity\User;
use App\Domain\Entity\WorksheetSubmission;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Learner nudges, sent by announcements:dispatch (cron):
 *
 *  - Daily study reminder: once a day, at the time the learner chose
 *    (Settings → Learning Preferences), in their school's time zone.
 *  - Weekly progress summary: Mondays from 08:00 school time, built from what
 *    the learner actually did in the previous seven days.
 *
 * Both are in-app notifications, emailed too when the learner allows email.
 * Each is on unless the learner switched it off.
 */
class StudyReminders
{
    private const DEFAULT_TIME = '19:00';
    /** Don't send a "daily" reminder more than this long after its time (e.g. cron was down). */
    private const LATE_LIMIT_MINUTES = 180;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array{0:int,1:int} [daily reminders sent, weekly summaries sent]. Flushes. */
    public function sendDue(?DateTimeImmutable $nowUtc = null): array
    {
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $daily = 0;
        $weekly = 0;
        foreach ($this->em->getRepository(Institution::class)->findBy(['status' => 'active']) as $institution) {
            /** @var Institution $institution */
            $now = $nowUtc->setTimezone($this->zone($institution));
            $today = new DateTimeImmutable($now->format('Y-m-d'));
            $monday = $today->modify('monday this week');
            $weeklyDue = $now->format('N') === '1' && $now->format('H:i') >= '08:00';

            $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.role', 'r')
                ->where('u.institution = :i')->andWhere('r.effectiveCode = :student')->andWhere('u.status = :active')
                ->setParameter('i', $institution)->setParameter('student', Role::STUDENT)->setParameter('active', 'active');
            // Only learners who still have something due today (or this week).
            $due = '(u.studyReminderOn IS NULL OR u.studyReminderOn < :today';
            $qb->setParameter('today', $today);
            if ($weeklyDue) {
                $due .= ' OR u.weeklySummaryOn IS NULL OR u.weeklySummaryOn < :monday';
                $qb->setParameter('monday', $monday);
            }
            $qb->andWhere($due . ')');

            foreach ($qb->getQuery()->getResult() as $student) {
                /** @var User $student */
                $learning = $student->getPreferences()['learning'] ?? [];

                if (($learning['daily_reminder'] ?? true) !== false
                    && ($student->getStudyReminderOn() === null || $student->getStudyReminderOn() < $today)) {
                    $time = preg_match('/^\d{2}:\d{2}$/', (string) ($learning['daily_reminder_time'] ?? '')) ? $learning['daily_reminder_time'] : self::DEFAULT_TIME;
                    $at = new DateTimeImmutable($today->format('Y-m-d') . ' ' . $time, $this->zone($institution));
                    $late = ($now->getTimestamp() - $at->getTimestamp()) / 60;
                    if ($late >= 0 && $late <= self::LATE_LIMIT_MINUTES) {
                        $this->notifications->notify(
                            $student,
                            'study_reminder',
                            'Time for today\'s study session',
                            'A little every day adds up. Pick up where you left off in My Subjects.',
                            '/student/academics/my-subjects',
                        );
                        $student->setStudyReminderOn($today);
                        $daily++;
                    }
                }

                if ($weeklyDue && ($learning['weekly_summary'] ?? true) !== false
                    && ($student->getWeeklySummaryOn() === null || $student->getWeeklySummaryOn() < $monday)) {
                    $this->notifications->notify(
                        $student,
                        'weekly_summary',
                        'Your week in review',
                        $this->summary($student, $nowUtc->modify('-7 days')),
                        '/student/academics/progress-feedback',
                    );
                    $student->setWeeklySummaryOn($today);
                    $weekly++;
                }
            }
            $this->em->flush();
        }

        return [$daily, $weekly];
    }

    /** What the learner actually did since $since, in a sentence or two. */
    private function summary(User $student, DateTimeImmutable $since): string
    {
        $lessons = (int) $this->em->createQueryBuilder()->select('COUNT(tp.id)')->from(TopicProgress::class, 'tp')
            ->where('tp.student = :s')->andWhere('tp.lessonViewedAt >= :since')
            ->setParameter('s', $student)->setParameter('since', $since)->getQuery()->getSingleScalarResult();

        $quiz = $this->em->createQueryBuilder()->select('COUNT(at.id) AS c', 'AVG(at.percentage) AS avg')
            ->from(AssessmentAttempt::class, 'at')
            ->where('at.student = :s')->andWhere('at.status = :g')->andWhere('at.submittedAt >= :since')
            ->setParameter('s', $student)->setParameter('g', AssessmentAttempt::GRADED)->setParameter('since', $since)
            ->getQuery()->getSingleResult();
        $quizzes = (int) $quiz['c'];

        $worksheets = (int) $this->em->createQueryBuilder()->select('COUNT(ws.id)')->from(WorksheetSubmission::class, 'ws')
            ->where('ws.student = :s')->andWhere('ws.submittedAt >= :since')
            ->setParameter('s', $student)->setParameter('since', $since)->getQuery()->getSingleScalarResult();

        if ($lessons + $quizzes + $worksheets === 0) {
            return 'No lessons, quizzes or worksheets were completed in the last 7 days. This week is a fresh start: try one lesson today.';
        }
        $parts = [];
        if ($lessons > 0) {
            $parts[] = $lessons . ($lessons === 1 ? ' lesson viewed' : ' lessons viewed');
        }
        if ($quizzes > 0) {
            $avg = $quiz['avg'] !== null ? ' (average ' . (int) round((float) $quiz['avg']) . '%)' : '';
            $parts[] = $quizzes . ($quizzes === 1 ? ' quiz completed' : ' quizzes completed') . $avg;
        }
        if ($worksheets > 0) {
            $parts[] = $worksheets . ($worksheets === 1 ? ' worksheet submitted' : ' worksheets submitted');
        }

        return 'In the last 7 days: ' . implode(', ', $parts) . '. Open Progress to see the detail.';
    }

    private function zone(Institution $institution): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($institution->getSettings()['localization']['timezone'] ?? 'Africa/Lagos'));
        } catch (\Throwable) {
            return new DateTimeZone('Africa/Lagos');
        }
    }
}
