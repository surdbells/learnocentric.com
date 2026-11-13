<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Entity\Enrollment;
use App\Domain\Entity\Institution;
use App\Domain\Entity\Role;
use App\Domain\Entity\SchoolClass;
use App\Domain\Entity\User;

/**
 * Who may take a piece of learner work (a worksheet, an assessment).
 *
 * The listing endpoints already show a learner only their own school's work for
 * their own classes; these checks apply the same rule when an item is opened
 * directly by its id. The using class provides $em.
 */
trait LearnerAccess
{
    /**
     * Why this user may not take work that belongs to $owner and is set for
     * $class (null = every class), or null when they may.
     *
     * Someone outside the school, or outside the class, is told "not found" so
     * the item's existence is not disclosed.
     *
     * @return array{0: string, 1: int}|null [message, HTTP status]
     */
    protected function learnerBlock(User $user, ?Institution $owner, ?SchoolClass $class, string $noun): ?array
    {
        $notFound = [ucfirst($noun) . ' not found.', 404];
        if ($owner === null || $user->getInstitution()?->getId() !== $owner->getId()) {
            return $notFound;
        }
        if ($user->getRole()->effectiveCode() !== Role::STUDENT) {
            $article = in_array(strtolower($noun[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

            return [sprintf('Only learners can take %s %s.', $article, $noun), 403];
        }
        if ($class !== null && !$this->isEnrolledIn($user, $class)) {
            return $notFound;
        }

        return null;
    }

    protected function isEnrolledIn(User $learner, SchoolClass $class): bool
    {
        return $this->em->getRepository(Enrollment::class)->findOneBy(['student' => $learner, 'schoolClass' => $class]) !== null;
    }
}
