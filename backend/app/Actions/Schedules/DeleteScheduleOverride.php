<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one schedule override row. The office-scope check (does the caller administer
 * this override's employee's office?) already happened in the controller — this action
 * trusts its input and only writes.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary for this override's employee (over-inclusion is safe — see CreateScheduleOverride).
 * $override's in-memory attributes remain readable after ->delete().
 */
final class DeleteScheduleOverride
{
    public function execute(ScheduleOverride $override): void
    {
        DB::transaction(function () use ($override): void {
            $override->delete();

            DB::afterCommit(function () use ($override): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forEmployee($override->employee_id),
                    RecomputeTrigger::ScheduleOverride,
                    $override->id,
                    "Schedule override {$override->id} deleted for employee {$override->employee_id}",
                );
            });
        });
    }
}
