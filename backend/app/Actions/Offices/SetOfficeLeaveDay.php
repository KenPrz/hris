<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Models\Office;
use Illuminate\Support\Facades\DB;

/**
 * Sets offices.minutes_per_leave_day — the nominal length of a leave day (default 480,
 * i.e. 8h), which the leave subsystem (M6b) will read to convert a leave request's
 * readable units (days/hours) into stored minutes. The office-scope check (does the
 * caller administer this office?) already happened in the controller — this action
 * trusts its input and only writes. Office self-logs via LogsActivity (M8a), and there is
 * no business rule beyond the write itself, so this stays a plain locked update, mirroring
 * CreateHoliday's office lock.
 */
final class SetOfficeLeaveDay
{
    public function execute(SetOfficeLeaveDayInput $in): Office
    {
        return DB::transaction(function () use ($in): Office {
            $office = Office::query()->lockForUpdate()->findOrFail($in->officeId);

            $office->update(['minutes_per_leave_day' => $in->minutesPerLeaveDay]);

            return $office;
        });
    }
}
