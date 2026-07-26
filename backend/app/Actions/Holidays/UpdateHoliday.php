<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Updates one holiday row. The office-scope check (does the caller administer this
 * holiday's office?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on Holiday records the `updated` event itself,
 * with the causer resolved from the authenticated guard automatically.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary on this holiday's office+date — its day type (or name) just changed, so anything
 * already computed for it is stale. Mirrors CreateHoliday/RecordPunch's afterCommit shape.
 */
final class UpdateHoliday
{
    public function execute(Holiday $holiday, UpdateHolidayInput $in): Holiday
    {
        return DB::transaction(function () use ($holiday, $in): Holiday {
            $holiday->update([
                'day_type' => $in->dayType,
                'name' => $in->name,
            ]);

            DB::afterCommit(function () use ($holiday): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forHoliday($holiday->office_id, [$holiday->date->toDateString()]),
                    RecomputeTrigger::Holiday,
                    $holiday->id,
                    "Holiday {$holiday->id} updated for office {$holiday->office_id} on {$holiday->date->toDateString()}",
                );
            });

            return $holiday;
        });
    }
}
