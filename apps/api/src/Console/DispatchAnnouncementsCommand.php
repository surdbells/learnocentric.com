<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\AnnouncementDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run from cron (every 5 minutes is plenty):
 *   php bin/console.php announcements:dispatch
 *
 * Sends scheduled announcements that are due and delivers the email channel
 * of announcements that asked for it. Safe to run concurrently-ish and
 * repeatedly: each announcement is marked as it is handled.
 */
#[AsCommand(name: 'announcements:dispatch', description: 'Send due scheduled announcements and deliver queued announcement emails')]
final class DispatchAnnouncementsCommand extends Command
{
    public function __construct(
        private readonly AnnouncementDispatcher $dispatcher,
        private readonly \App\Service\LiveClassReminders $reminders,
        private readonly \App\Service\StudyReminders $study,
        private readonly \App\Service\Billing\SubscriptionNotices $billing,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->dispatcher->sendDue();
        [$announcements, $emails] = $this->dispatcher->emailPending();
        $reminded = $this->reminders->sendDue();
        [$daily, $weekly] = $this->study->sendDue();
        $subscriptions = $this->billing->sendDue();
        $output->writeln(sprintf(
            'Scheduled announcements sent: %d. Emailed: %d announcement(s), %d email(s). Live-class reminders: %d class(es). Study reminders: %d daily, %d weekly. Subscription notices: %d.',
            $sent, $announcements, $emails, $reminded, $daily, $weekly, $subscriptions
        ));

        return Command::SUCCESS;
    }
}
