<?php

declare(strict_types=1);

namespace App\Application\Actions\School;

use App\Domain\Entity\Role;

/** /backend/school/teachers, paginated list, delete, bulk delete. */
final class TeachersAction extends AbstractUsersResourceAction
{
    protected function roleCode(): string
    {
        return Role::TEACHER;
    }
}
