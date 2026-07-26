<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one holiday row. The office-scope check (does the caller administer this
 * holiday's office?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on Holiday records the `deleted` event itself,
 * with the causer resolved from the authenticated guard automatically.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary on the deleted holiday's office+date — the day reverts to Ordinary, so anything
 * already computed against the removed holiday is stale. $holiday's in-memory attributes
 * (office_id, date) remain readable after ->delete(); nothing here re-reads the row.
 */
final class DeleteHoliday
{
    public function execute(Holiday $holiday): void
    {
        DB::transaction(function () use ($holiday): void {
            $holiday->delete();

            DB::afterCommit(function () use ($holiday): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forHoliday($holiday->office_id, [$holiday->date->toDateString()]),
                    RecomputeTrigger::Holiday,
                    $holiday->id,
                    "Holiday {$holiday->id} deleted for office {$holiday->office_id} on {$holiday->date->toDateString()}",
                );
            });
        });
    }
}
