<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Entity\Role;

/**
 * The platform-defined permission matrix for the system roles, enforced on
 * every request by RequirePermissionMiddleware (route → module → action).
 *
 * Letters: V view, C create, E edit, A approve, X export, D delete, R archive.
 * This is the security floor: it blocks, for example, learners and parents
 * from writing curriculum, assessment or school-setup records. Endpoints still
 * apply their own finer rules (own records, same school, lifecycle roles).
 * Learner self-service endpoints (taking a worksheet, own portfolio, joining a
 * live class…) are exempt in the middleware rather than granted here.
 */
final class SystemRoleMatrix
{
    private const ADMIN = [
        'role' => 'VCE--D-',
        'user' => 'VCE--D-',
        'academic_setup' => 'VCE--D-',
        'curriculum_pack' => 'VCEA-DR',
        'delivery_pack' => 'VCEA-DR',
        'assessment' => 'VCEA-DR',
        'worksheet' => 'VCEA-DR',
        'portfolio' => 'VCE----',
        'gradebook' => 'V------',
        'intervention' => 'VCE--D-',
        'safeguarding_case' => 'VCE----',
        'live_class' => 'VCE--D-',
        'report' => 'V---X--',
        'resource' => 'VC---D-',
        'subscription' => 'VC-----',
        'support_ticket' => 'VC-----',
        'institution' => 'V------',
    ];

    private const ACADEMIC_LEAD = [
        'user' => 'VCE--D-',
        'academic_setup' => 'VCE--D-',
        'curriculum_pack' => 'VCEA-DR',
        'delivery_pack' => 'VCEA-DR',
        'assessment' => 'VCEA-DR',
        'worksheet' => 'VCEA-DR',
        'portfolio' => 'VCE----',
        'gradebook' => 'V------',
        'intervention' => 'VCE--D-',
        'safeguarding_case' => '-C-----',
        'live_class' => 'VCE--D-',
        'report' => 'V---X--',
        'resource' => 'VC---D-',
        'support_ticket' => 'VC-----',
        'institution' => 'V------',
    ];

    private const TEACHER = [
        'user' => 'V------',
        'academic_setup' => 'V------',
        'curriculum_pack' => 'VCE--D-',
        'delivery_pack' => 'VCE--D-',
        'assessment' => 'VCE--D-',
        'worksheet' => 'VCE--D-',
        'portfolio' => 'VCE----',
        'gradebook' => 'V------',
        'intervention' => 'VCE----',
        'safeguarding_case' => '-C-----',
        'live_class' => 'VCE--D-',
        'report' => 'V---X--',
        'resource' => 'VC---D-',
        'support_ticket' => 'VC-----',
        'institution' => 'V------',
    ];

    private const LEARNER = [
        'academic_setup' => 'V------',
        'resource' => 'V------',
        'safeguarding_case' => '-C-----',
        'support_ticket' => 'VC-----',
        'institution' => 'V------',
    ];

    private const LETTERS = ['V' => 'view', 'C' => 'create', 'E' => 'edit', 'A' => 'approve', 'X' => 'export', 'D' => 'delete', 'R' => 'archive'];

    /** @return array<string,array<string,array<string,bool>>> role code => module => action flags */
    public static function all(): array
    {
        $raw = [
            Role::SCHOOL_ADMIN => self::ADMIN,
            Role::TUTOR_ADMIN => self::ADMIN,
            Role::ACADEMIC_LEAD => self::ACADEMIC_LEAD,
            Role::TEACHER => self::TEACHER,
            Role::STUDENT => self::LEARNER,
            Role::PARENT => self::LEARNER,
        ];
        $out = [];
        foreach ($raw as $role => $modules) {
            foreach ($modules as $module => $letters) {
                $flags = [];
                foreach (self::LETTERS as $letter => $action) {
                    $flags[$action] = str_contains($letters, $letter);
                }
                $out[$role][$module] = $flags;
            }
        }
        return $out;
    }
}
