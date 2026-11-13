<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Entity\User;

/**
 * The SPA area a user lives in, for links the API hands out (notifications,
 * search results). Mirrors apps/web/src/app/common/auth/portal.ts.
 */
final class Portal
{
    public static function root(User $user): string
    {
        return match ($user->getRole()->effectiveCode()) {
            'super_admin' => '/super-admin',
            'tutor_admin' => '/academy',
            'teacher' => '/teacher',
            'student' => '/student',
            'parent' => '/parent',
            default => '/admin', // school_admin, academic_lead
        };
    }

    /** e.g. Portal::link($user, '/academics/interventions'). */
    public static function link(User $user, string $path): string
    {
        return self::root($user) . $path;
    }
}
