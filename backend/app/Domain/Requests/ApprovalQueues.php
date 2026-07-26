<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Employee;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two scoped views of the pending queue. Each is a subset of the in-scope-minus-self
 * set RequestAuthority::canDecide accepts — /team by the org chart (direct reports),
 * /office by HR office membership — so leave and overtime appear in them automatically
 * (no type filter). The two queues are VIEWS, not a new authority: canDecide is unchanged.
 *
 * @return Builder<Request>
 */
final class ApprovalQueues
{
    public static function directReportsOf(User $user): Builder
    {
        $selfEmployeeId = $user->employee?->id;

        // A bare actor with no Employee record (e.g. a system-admin-only account) has no
        // org-chart position, so has no reports — never "everyone with no manager set."
        // Laravel's query builder rewrites where('col', null) to whereNull('col'), which
        // would otherwise match every managerless employee rather than returning nothing;
        // short-circuit before that footgun, mirroring EmployeeScope::visibleTo()'s guard
        // for the same "no self, no scope" case.
        if ($selfEmployeeId === null) {
            return self::pending()->whereRaw('1 = 0');
        }

        $reportIds = Employee::query()
            ->where('current_reports_to_id', $selfEmployeeId)
            ->pluck('id');

        return self::pending()
            ->whereIn('employee_id', $reportIds)
            ->where('employee_id', '!=', $selfEmployeeId);
    }

    public static function hrOfficesOf(User $user): Builder
    {
        $officeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        $memberIds = Employee::query()
            ->whereIn('current_office_id', $officeIds)
            ->pluck('id');

        return self::pending()
            ->whereIn('employee_id', $memberIds)
            ->where('employee_id', '!=', $user->employee?->id);
    }

    /** @return Builder<Request> */
    private static function pending(): Builder
    {
        return Request::query()->where('state', RequestState::Pending)->latest();
    }
}
