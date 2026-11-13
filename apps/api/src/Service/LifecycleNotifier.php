<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Support\Portal;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Notification;
use App\Domain\Entity\User;
use App\Domain\Lifecycle;
use App\Domain\LifecycleAware;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells the people a lifecycle move concerns, so nothing waits unseen:
 * approvers when something is submitted for them, and the person who
 * submitted it when a decision has been made.
 *
 * In-app only. Callers flush.
 */
final class LifecycleNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LifecycleService $lifecycle,
        private readonly NotificationService $notify,
    ) {
    }

    /**
     * @param string           $noun  what the item is called ("assessment", "question", "lesson pack")
     * @param string           $title the item's own title, as people know it
     * @param Institution|null $owner the school it belongs to (null for platform content)
     * @param string           $path  the page that lists these items, under the portal root ("/academics/assessments")
     */
    public function announce(
        LifecycleAware $entity,
        string $noun,
        string $title,
        ?Institution $owner,
        string $from,
        string $to,
        User $actor,
        ?string $note,
        string $path,
    ): void {
        $title = mb_strimwidth(trim(strip_tags($title)), 0, 80, '…');
        $a = in_array(strtolower($noun[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A';

        // Submitted: tell everyone who can approve it.
        if ($to === Lifecycle::REVIEW && $from === Lifecycle::DRAFT) {
            $heading = sprintf('%s %s is waiting for your approval', $a, $noun);
            foreach ($this->lifecycle->approversOf($owner)['users'] as $approver) {
                // Someone submitting a batch one by one should not bury the approver:
                // one unread reminder per kind of item is enough to bring them to the queue.
                if ($approver->getId() === $actor->getId() || $this->alreadyReminded($approver, $heading)) {
                    continue;
                }
                $this->notify->notify(
                    $approver,
                    'system',
                    $heading,
                    sprintf('%s submitted "%s" for review. Open the approval queue to see everything that is waiting.', $actor->displayName(), $title),
                    Portal::link($approver, '/academics/approval-queue'),
                    false,
                );
            }

            return;
        }

        // Decided: tell the person who submitted it.
        $authorId = $this->lifecycle->lastSubmitterId($entity);
        if ($authorId === null || $authorId === $actor->getId()) {
            return;
        }
        $author = $this->em->getRepository(User::class)->find($authorId);
        if ($author === null) {
            return;
        }
        $note = trim((string) $note);
        [$heading, $message] = match (true) {
            $to === Lifecycle::PUBLISHED => [
                sprintf('Your %s was published', $noun),
                sprintf('"%s" is now published.', $title),
            ],
            $to === Lifecycle::APPROVED => [
                sprintf('Your %s was approved', $noun),
                sprintf('"%s" was approved. It still needs publishing before learners can use it.', $title),
            ],
            $to === Lifecycle::DRAFT && $from === Lifecycle::REVIEW => [
                sprintf('Your %s was returned', $noun),
                sprintf('"%s" was returned for changes.%s', $title, $note !== '' ? ' Note: ' . $note : ''),
            ],
            default => [null, null],
        };
        if ($heading !== null) {
            $this->notify->notify($author, 'system', $heading, $message, Portal::link($author, $path), false);
        }
    }

    private function alreadyReminded(User $approver, string $heading): bool
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(n.id)')
            ->from(Notification::class, 'n')
            ->where('n.user = :u')->andWhere('n.title = :t')->andWhere('n.read = false')->andWhere('n.createdAt > :since')
            ->setParameter('u', $approver)->setParameter('t', $heading)->setParameter('since', new DateTimeImmutable('-6 hours'))
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
