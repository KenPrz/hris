<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Exceptions\Domain\HolidayExists;
use App\Models\Holiday;
use App\Models\Office;
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
        return DB::transaction(function () use ($in): Holiday {
            // Lock the office row so two admins creating the same office-date can't both
            // pass the pre-check and race to the insert — the second blocks here, then
            // cleanly sees the committed row below. Mirrors RecordEmploymentChange. The
            // unique(office_id, date) constraint remains the ultimate backstop.
            Office::query()->lockForUpdate()->findOrFail($in->officeId);

            $duplicate = Holiday::query()
                ->where('office_id', $in->officeId)
                ->whereDate('date', $in->date)
                ->exists();

            if ($duplicate) {
                throw new HolidayExists($in->officeId, $in->date);
            }

            return Holiday::query()->create([
                'office_id' => $in->officeId,
                'date' => $in->date,
                'day_type' => $in->dayType,
                'name' => $in->name,
            ]);
        });
    }
}
