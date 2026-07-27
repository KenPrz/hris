<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

use App\Domain\Cutoff\CutoffState;
use App\Exceptions\Domain\CutoffNotClosed;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reopens a closed cutoff period: refuses on a period that is not `closed`, and otherwise
 * flips every in-period `locked` summary back to `computed` and the period back to `open`.
 * The mirror image of CloseCutoff.
 *
 * Takes the same per-employee row lock CloseCutoff/ComputeDailySummary/ApproveRequest take,
 * per affected employee, BEFORE flipping that employee's summaries — so a reopen serializes
 * against a concurrent recompute or approval for the same employee. Only rows still
 * `locked` are touched; a `disputed`/`pending` row left that way for another reason is not
 * disturbed. Reopening always requires a reason, and is loudly audited with it — the
 * FormRequest (Task 8) enforces non-empty at the HTTP boundary; this is the defensive,
 * direct-call guard.
 */
final class ReopenCutoff
{
    public function execute(ReopenCutoffInput $in): CutoffPeriod
    {
        if (trim($in->reason) === '') {
            throw new InvalidArgumentException('A reopen reason is required.');
        }

        return DB::transaction(function () use ($in): CutoffPeriod {
            $period = CutoffPeriod::query()->lockForUpdate()->findOrFail($in->periodId);

            if ($period->state !== CutoffState::Closed) {
                throw new CutoffNotClosed($period->id);
            }

            $summaries = DailyAttendanceSummary::query()
                ->where('office_id', $period->office_id)
                ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
                ->get();

            foreach ($summaries->pluck('employee_id')->unique() as $employeeId) {
                Employee::query()->lockForUpdate()->findOrFail($employeeId);

                DailyAttendanceSummary::query()
                    ->where('employee_id', $employeeId)
                    ->where('office_id', $period->office_id)
                    ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
                    ->where('status', 'locked')
                    ->update(['status' => 'computed']);
            }

            $period->update([
                'state' => CutoffState::Open->value,
                'closed_by' => null,
                'closed_at' => null,
            ]);

            activity()
                ->performedOn($period)
                ->causedBy($in->actorId)
                ->withProperties(['reason' => $in->reason])
                ->log('cutoff_reopened');

            return $period->fresh();
        });
    }
}
