<?php

declare(strict_types=1);

namespace App\Domain;

/** Content lifecycle states (spec §13): Draft → Review → Approved → Published → Archived. */
final class Lifecycle
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    public const ALL = [self::DRAFT, self::REVIEW, self::APPROVED, self::PUBLISHED, self::ARCHIVED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
