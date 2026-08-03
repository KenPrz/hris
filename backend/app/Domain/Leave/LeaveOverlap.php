<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Exceptions\Domain\OverlappingLeave;
use App\Models\Request;

/**
 * Refuses an approval whose date span overlaps leave this employee already holds approved.
 *
 * There was no such invariant anywhere: `leave_details` carries only a primary key, and the
 * schema has no exclusion constraints at all. Neither SubmitLeaveRequest nor ApproveRequest
 * checked, and LeaveEffect checked only that the balance sufficed — so two overlapping
 * requests both reached final approval, each writing a ledger debit, while the compute path
 * emitted one leave_with_pay line per day. Charged twice, paid once.
 *
 * A database exclusion constraint would be the stronger form, but the range lives across two
 * columns on a side table with no employee_id, so it would need that column denormalized
 * onto `leave_details` first. This check instead runs inside LeaveEffect under the employee
 * row lock ApproveRequest already takes — which is what makes two concurrent approvals
 * serialize rather than both finding the span free.
 *
 * A query-builder wrapper over Eloquent, the same shape as LeaveDayLookup/EmployeeScope —
 * domain-Eloquent is allowed here for the same reason it is allowed there.
 */
final class LeaveOverlap
{
    private function __construct() {}

    public static function assertNoneFor(
        string $employeeId,
        string $startDate,
        string $endDate,
        string $exceptRequestId,
    ): void {
        $conflict = Request::query()
            ->where('employee_id', $employeeId)
            ->where('type', RequestType::Leave)
            ->where('state', RequestState::Approved)
            ->whereKeyNot($exceptRequestId)
            ->whereHas('leaveDetail', function ($query) use ($startDate, $endDate): void {
                // Two closed ranges overlap iff each starts on or before the other ends.
                $query->whereDate('start_date', '<=', $endDate)
                    ->whereDate('end_date', '>=', $startDate);
            })
            ->first();

        if ($conflict !== null) {
            throw new OverlappingLeave($conflict->id);
        }
    }
}
