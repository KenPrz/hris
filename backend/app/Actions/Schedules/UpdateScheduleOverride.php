<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

/**
 * Updates one schedule override row (is_rest, hours, note — never the employee or date;
 * those are fixed by the route-bound row). The office-scope check (does the caller
 * administer this override's employee's office?) already happened in the controller —
 * this action trusts its input and only writes.
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

            return $override;
        });
    }
}
