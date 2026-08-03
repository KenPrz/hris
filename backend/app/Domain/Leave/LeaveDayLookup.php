<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;

/**
 * The paid-leave minutes attributable to one calendar date — the sum of `minutes_per_day`
 * across every APPROVED (final-hop, not merely manager_approved) `leave` request whose
 * [start_date, end_date] span covers it. The one fact DailyComputation needs to price a day
 * as leave_with_pay — it never queries the database itself (see DailyComputation's purity
 * contract), so ComputeDailySummary resolves this and hands it in on DailyComputationInput.
 *
 * Minutes, not a boolean. As a boolean, `leave_details.day_part` was written at submit and
 * read by nothing downstream, so a half-day leave was priced as a full day: debited 240,
 * paid 480. Reading `minutes_per_day` — the same snapshot `amount_minutes` (and therefore
 * LeaveEffect's ledger debit) is a multiple of — means the pay side and the debit side
 * cannot disagree about what a leave day is worth.
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

    public static function paidMinutesFor(Employee $employee, string $date): int
    {
        return (int) Request::query()
            ->where('requests.employee_id', $employee->id)
            ->where('requests.type', RequestType::Leave)
            ->where('requests.state', RequestState::Approved)
            ->whereHas('leaveDetail', function ($query) use ($date): void {
                $query->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->whereHas('leaveType', function ($query): void {
                        $query->where('is_paid', true);
                    });
            })
            ->join('leave_details', 'leave_details.request_id', '=', 'requests.id')
            ->sum('leave_details.minutes_per_day');
    }
}
