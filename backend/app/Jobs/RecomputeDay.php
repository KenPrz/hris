<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Compute\ComputeDailySummary;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

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
 * A `locked` summary is frozen — a locked cutoff period's numbers are final as of M7. The
 * AUTHORITATIVE freeze guard now lives in ComputeDailySummary::execute, UNDER the employee
 * row lock: it reads the covering cutoff period there and skips the whole delete/recompute/
 * create when the (office, date) is in a `closed` period, so a close racing a recompute
 * serializes on that lock (M7a). The early `status === 'locked'` read below is retained only
 * as a cheap unlocked fast-path — it short-circuits the common already-frozen case before
 * dispatching a job, but it is NOT the race-safe guard (an unlocked read alone was the
 * M5b-flagged race, and it cannot see a summary-less closed day at all). Correctness rests on
 * the in-transaction check in ComputeDailySummary; this fast-path is harmless and optional.
 */
final class RecomputeDay implements ShouldQueue
{
    // InteractsWithQueue is not just boilerplate: CallQueuedHandler::ensureSuccessfulBatchJobIsRecorded()
    // requires BOTH Batchable and InteractsWithQueue to be present on the job class before it will call
    // $batch->recordSuccessfulJob() — Batchable alone (which is all this job needs for the
    // $this->batch()?->cancelled() check above) silently leaves every batch containing this job stuck
    // at pendingJobs > 0 forever, so RecomputeRange's Bus::batch(...)->then() would never fire.
    use Batchable, InteractsWithQueue, Queueable;

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
