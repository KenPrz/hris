<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Employee;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two scoped views of the queue awaiting each hat's decision. Each is a subset of the
 * in-scope-minus-self set RequestAuthority::canDecide accepts — /team by the org chart
 * (direct reports), /office by HR office membership. /team is state=pending only (the
 * manager's hop, for both single- and two-hop types); /office is hop-aware — a single-hop
 * type as soon as it's pending, or ANY type once it reaches manager_approved (HR's hop).
 * The two queues are VIEWS, not a new authority: canDecide is unchanged.
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

        // single-hop request types (those with requiresHrStep()===false). Kept explicit and
        // in sync with RequestType::requiresHrStep() — as new single-hop types are added,
        // list them here.
        $singleHopTypes = [RequestType::AttendanceAdjustment->value];

        // /office awaits HR's hop: a single-hop request is HR's the moment it's pending (HR
        // is the only decider it ever needs), while a two-hop request only reaches HR once
        // the manager has cleared hop 1 (manager_approved) — a two-hop request still in
        // `pending` belongs to /team alone.
        return Request::query()
            ->whereIn('employee_id', $memberIds)
            ->where('employee_id', '!=', $user->employee?->id)
            ->where(function (Builder $q) use ($singleHopTypes): void {
                $q->where(fn (Builder $s) => $s->where('state', RequestState::Pending)->whereIn('type', $singleHopTypes))
                    ->orWhere('state', RequestState::ManagerApproved);
            })
            ->latest();
    }

    /** @return Builder<Request> */
    private static function pending(): Builder
    {
        return Request::query()->where('state', RequestState::Pending)->latest();
    }
}
