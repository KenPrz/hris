<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;

/**
 * Assembles the /me session — the single source of scope truth the frontend reads. Kept
 * out of the controller because the seeder and tests build sessions too.
 */
final class BuildSession
{
    public function execute(User $user): SessionData
    {
        $employee = $user->employee;

        $hasReports = $employee !== null
            && Employee::query()->where('current_reports_to_id', $employee->id)->exists();

        return new SessionData(
            user: $user,
            employee: $employee,
            // Cast, not just passed through: a freshly-inserted Postgres row doesn't
            // return column defaults, so an in-memory User created without this
            // attribute set explicitly holds null here, not false.
            isSystemAdmin: (bool) $user->is_system_admin,
            hasReports: $hasReports,
            hrOffices: $user->hrAdminOffices()->pluck('offices.id')->all(),
            permissions: $user->getAllPermissions()->pluck('name')->all(),
        );
    }
}
