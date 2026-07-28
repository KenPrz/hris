<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

use App\Domain\Cutoff\CutoffCalendar;
use App\Domain\Cutoff\CutoffState;
use App\Domain\Cutoff\RequestAffectedDates;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\CutoffAlreadyClosed;
use App\Exceptions\Domain\CutoffHasUnresolvedExceptions;
use App\Exceptions\Domain\InvalidCutoffStart;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Closes an office's semi-monthly cutoff period: refuses on an invalid boundary, an
 * already-closed period, or any unresolved exception (an incomplete in-period day, or a
 * non-terminal request whose effect maps onto an in-period date), and otherwise freezes
 * every in-period summary to `locked`.
 *
 * The freeze takes the per-employee row lock ComputeDailySummary/ApproveRequest also take,
 * per affected employee, BEFORE flipping that employee's summaries — so a close serializes
 * against a concurrent recompute or approval for the same employee (see the two-connection
 * tests). Setting the period `closed` and the summaries `locked` happen in one transaction.
 */
final class CloseCutoff
{
    public function execute(CloseCutoffInput $in): CutoffPeriod
    {
        if (! CutoffCalendar::isValidStart($in->periodStart)) {
            throw new InvalidCutoffStart($in->periodStart);
        }

        $window = CutoffCalendar::windowFor($in->periodStart);

        return DB::transaction(function () use ($in, $window): CutoffPeriod {
            $period = CutoffPeriod::query()->lockForUpdate()->firstOrCreate(
                ['office_id' => $in->officeId, 'start_date' => $window['start']],
                ['end_date' => $window['end'], 'state' => CutoffState::Open->value],
            );

            if ($period->state === CutoffState::Closed) {
                throw new CutoffAlreadyClosed($period->id);
            }

            // --- Strict exception gate ---
            $summaries = DailyAttendanceSummary::query()
                ->where('office_id', $in->officeId)
                ->whereBetween('date', [$window['start'], $window['end']])
                ->get();

            $incompleteDates = $summaries->where('is_incomplete', true)
                ->map(fn (DailyAttendanceSummary $s): string => $s->date->toDateString())
                ->values()->all();

            $pendingRequestIds = self::blockingRequestIds($in->officeId, $window['start'], $window['end']);

            if ($incompleteDates !== [] || $pendingRequestIds !== []) {
                throw new CutoffHasUnresolvedExceptions($incompleteDates, $pendingRequestIds);
            }

            // --- Freeze, per-employee under the shared row lock ---
            // Sort the employee ids before locking so every cutoff operation on this office
            // acquires the shared Employee row locks in the SAME (ascending id) order. Two
            // concurrent multi-employee closes/reopens on the same office would otherwise be
            // free to take overlapping locks in opposite orders — a classic AB-BA deadlock
            // Postgres aborts as a 500. A total lock order makes them queue instead.
            foreach ($summaries->pluck('employee_id')->unique()->sort()->values() as $employeeId) {
                Employee::query()->lockForUpdate()->findOrFail($employeeId);

                DailyAttendanceSummary::query()
                    ->where('employee_id', $employeeId)
                    ->where('office_id', $in->officeId)
                    ->whereBetween('date', [$window['start'], $window['end']])
                    ->update(['status' => 'locked']);
            }

            $period->update([
                'end_date' => $window['end'],
                'state' => CutoffState::Closed->value,
                'closed_by' => $in->actorId,
                'closed_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * Non-terminal requests (pending/manager_approved) whose effect maps onto an in-period
     * date for an employee in this office. Scanned across the three request types via
     * RequestAffectedDates. @return array<int, string>
     */
    private static function blockingRequestIds(string $officeId, string $start, string $end): array
    {
        $employeeIds = Employee::query()->where('current_office_id', $officeId)->pluck('id');

        $candidates = Request::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('state', [RequestState::Pending->value, RequestState::ManagerApproved->value])
            ->with(['attendanceAdjustmentDetail.targetLog', 'leaveDetail', 'overtimeDetail', 'employee.currentOffice'])
            ->get();

        return $candidates
            ->filter(function (Request $request) use ($start, $end): bool {
                foreach (RequestAffectedDates::for($request) as $date) {
                    if ($date >= $start && $date <= $end) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')->values()->all();
    }
}
