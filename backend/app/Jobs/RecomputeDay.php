<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Compute\ComputeDailySummary;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Recomputes one employee-day by re-running ComputeDailySummary — the queued unit of
 * work RecomputeRange (M5b's next task) will fan a date range out into, and the same
 * unit a single out-of-band change (a holiday added late, a corrected schedule, an
 * approved adjustment) dispatches on its own.
 *
 * Carries only the ids, never the Employee model: jobs are serialized onto the queue
 * connection, and an id round-trips through that cleanly where a full model would not
 * (and would also silently go stale between dispatch and execution).
 *
 * A `locked` summary is frozen — a locked cutoff period's numbers are final as of M7 —
 * so this job is a strict no-op over one: it does not delete it, does not recompute it,
 * does not touch it at all. Everything else about ComputeDailySummary::execute's own
 * idempotency (delete-then-insert under a row lock) is unchanged; this job adds exactly
 * one guard in front of it.
 */
final class RecomputeDay implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public readonly string $employeeId,
        public readonly string $date,
    ) {}

    public function handle(ComputeDailySummary $action): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $existing = DailyAttendanceSummary::query()
            ->where('employee_id', $this->employeeId)
            ->whereDate('date', $this->date)
            ->first();

        // A locked period's numbers are frozen (M7 cutoffs). Never recompute over a lock.
        if ($existing?->status === 'locked') {
            return;
        }

        $employee = Employee::query()->find($this->employeeId);

        if ($employee === null) {
            return;
        }

        $action->execute($employee, $this->date);
    }
}
