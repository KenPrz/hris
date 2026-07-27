<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;

/**
 * Whether one calendar date falls inside an APPROVED (final-hop, not merely
 * manager_approved) full-day `leave` request's [start_date, end_date] span for a given
 * employee. The one fact DailyComputation needs to price a day as leave_with_pay — it
 * never queries the database itself (see DailyComputation's purity contract), so
 * ComputeDailySummary resolves this and hands it in on DailyComputationInput.
 *
 * A query-builder wrapper over Eloquent, the same shape as EmployeeScope/OfficeScope/
 * ApprovalQueues — domain-Eloquent is allowed here for the same reason it's allowed there.
 *
 * Also gates on the covering leave type's `is_paid` flag: `is_paid` is a distinct,
 * required, admin-settable column (separate from `deducts_balance`), so an admin can
 * create a Leave-Without-Pay type. An approved LWOP day must NOT match here — otherwise
 * DailyComputation would price it as leave_with_pay at 100%, a mispay. An unpaid approved
 * leave day instead falls through to the normal unworked/absent computation.
 */
final class LeaveDayLookup
{
    private function __construct() {}

    public static function isOnApprovedLeave(Employee $employee, string $date): bool
    {
        return Request::query()
            ->where('employee_id', $employee->id)
            ->where('type', RequestType::Leave)
            ->where('state', RequestState::Approved)
            ->whereHas('leaveDetail', function ($query) use ($date): void {
                $query->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->whereHas('leaveType', function ($query): void {
                        $query->where('is_paid', true);
                    });
            })
            ->exists();
    }
}
