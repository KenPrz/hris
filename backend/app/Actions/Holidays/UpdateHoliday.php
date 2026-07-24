<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Updates one holiday row. The office-scope check (does the caller administer this
 * holiday's office?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on Holiday records the `updated` event itself,
 * with the causer resolved from the authenticated guard automatically.
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

            return $holiday;
        });
    }
}
