<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Content lifecycle state machine (spec §13):
 * Draft → Review → Approved → Published → Archived, with returns and takedown.
 */
final class Lifecycle
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    public const ALL = [self::DRAFT, self::REVIEW, self::APPROVED, self::PUBLISHED, self::ARCHIVED];

    /** Allowed transitions: from => [to => action name]. */
    private const TRANSITIONS = [
        self::DRAFT => [self::REVIEW => 'submit'],
        self::REVIEW => [self::APPROVED => 'approve', self::DRAFT => 'return'],
        self::APPROVED => [self::PUBLISHED => 'publish', self::REVIEW => 'recall'],
        self::PUBLISHED => [self::ARCHIVED => 'archive'],
        self::ARCHIVED => [self::DRAFT => 'restore'],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        // Emergency takedown is always available (except no-op).
        if ($to === self::ARCHIVED && $from !== self::ARCHIVED) {
            return true;
        }

        return isset(self::TRANSITIONS[$from][$to]);
    }

    public static function actionFor(string $from, string $to): string
    {
        if ($to === self::ARCHIVED && !isset(self::TRANSITIONS[$from][$to])) {
            return 'takedown';
        }

        return self::TRANSITIONS[$from][$to] ?? 'transition';
    }

    /** @return string[] statuses reachable from the given status */
    public static function nextStates(string $from): array
    {
        $states = array_keys(self::TRANSITIONS[$from] ?? []);
        if ($from !== self::ARCHIVED && !in_array(self::ARCHIVED, $states, true)) {
            $states[] = self::ARCHIVED; // takedown
        }

        return $states;
    }
}
