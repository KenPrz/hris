<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which employees may this user see." Returns a query constraint,
 * not a boolean, so it composes into any index query and there is exactly one place the
 * boundary lives. A policy that checks a verb but forgets to apply this is the bug this
 * exists to prevent — an arch test asserts every index action routes through here.
 *
 * The four scopes compose ADDITIVELY: self, direct reports, HR offices, and (for a system
 * admin) everything. See docs/05-rbac.md.
 *
 * This is a Domain service that touches Eloquent, which is allowed for a Scope/query
 * builder — the M1 config-purity rule bars config() and facades from Domain, not the ORM.
 */
final class EmployeeScope
{
    public static function visibleTo(User $user): Builder
    {
        $query = Employee::query();

        // System admin: unconstrained. (Gate::before also short-circuits policy checks,
        // but index queries call this directly, so the scope must grant all here too.)
        if ($user->is_system_admin) {
            return $query;
        }

        $selfEmployeeId = $user->employee?->id;
        $hrOfficeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        return $query->where(function (Builder $q) use ($user, $selfEmployeeId, $hrOfficeIds): void {
            // Self.
            if ($selfEmployeeId !== null) {
                $q->orWhere('id', $selfEmployeeId);
                // Direct reports (manager scope is derived from the org chart).
                $q->orWhere('current_reports_to_id', $selfEmployeeId);
            }

            // HR offices.
            if ($hrOfficeIds !== []) {
                $q->orWhereIn('current_office_id', $hrOfficeIds);
            }

            // A user with no employee, no reports, and no HR offices sees nothing. Force an
            // empty result rather than an unconstrained one.
            if ($selfEmployeeId === null && $hrOfficeIds === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
