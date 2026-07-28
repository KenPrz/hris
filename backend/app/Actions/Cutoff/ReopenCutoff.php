<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

use App\Domain\Cutoff\CutoffState;
use App\Exceptions\Domain\CutoffNotClosed;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\User;
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

            // Sort the employee ids before locking so every cutoff operation on this office
            // acquires the shared Employee row locks in the SAME (ascending id) order. Two
            // concurrent multi-employee closes/reopens on the same office would otherwise be
            // free to take overlapping locks in opposite orders — a classic AB-BA deadlock
            // Postgres aborts as a 500. A total lock order makes them queue instead.
            foreach ($summaries->pluck('employee_id')->unique()->sort()->values() as $employeeId) {
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

            // causedBy() takes a Model, not a bare id (see CloneHolidays::execute for the
            // same convention) — passing the raw uuid string instead makes Spatie's
            // CauserResolver fall back to resolveUsingId(), which resolves the *current
            // default auth guard's* provider. Inside a real request that guard is
            // 'sanctum' (Authenticate::authenticate() calls Auth::shouldUse() on success),
            // and Sanctum's guard has no getProvider() — a crash that only ever surfaces
            // when this action is driven over HTTP (Task 8), never from a direct/console
            // call. Resolving the model ourselves sidesteps guard resolution entirely.
            activity()
                ->performedOn($period)
                ->causedBy(User::find($in->actorId))
                ->withProperties(['reason' => $in->reason])
                ->log('cutoff_reopened');

            return $period->fresh();
        });
    }
}
