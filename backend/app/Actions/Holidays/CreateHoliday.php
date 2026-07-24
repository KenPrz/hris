<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Creates one holiday row for an office. The office-scope check (is this office one the
 * caller administers?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on Holiday records the `created` event itself,
 * with the causer resolved from the authenticated guard automatically.
 */
final class CreateHoliday
{
    public function execute(CreateHolidayInput $in): Holiday
    {
        return DB::transaction(fn (): Holiday => Holiday::query()->create([
            'office_id' => $in->officeId,
            'date' => $in->date,
            'day_type' => $in->dayType,
            'name' => $in->name,
        ]));
    }
}
