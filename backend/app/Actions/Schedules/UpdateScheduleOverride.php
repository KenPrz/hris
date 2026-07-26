<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

/**
 * Updates one schedule override row (is_rest, hours, note — never the employee or date;
 * those are fixed by the route-bound row). The office-scope check (does the caller
 * administer this override's employee's office?) already happened in the controller —
 * this action trusts its input and only writes.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary for this override's employee (over-inclusion is safe — see CreateScheduleOverride).
 */
final class UpdateScheduleOverride
{
    public function execute(ScheduleOverride $override, UpdateScheduleOverrideInput $in): ScheduleOverride
    {
        return DB::transaction(function () use ($override, $in): ScheduleOverride {
            $override->update([
                'is_rest' => $in->isRest,
                'start_minute' => $in->startMinute,
                'end_minute' => $in->endMinute,
                'break_minutes' => $in->breakMinutes,
                'note' => $in->note,
            ]);

            DB::afterCommit(function () use ($override): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forEmployee($override->employee_id),
                    RecomputeTrigger::ScheduleOverride,
                    $override->id,
                    "Schedule override {$override->id} updated for employee {$override->employee_id}",
                );
            });

            return $override;
        });
    }
}
