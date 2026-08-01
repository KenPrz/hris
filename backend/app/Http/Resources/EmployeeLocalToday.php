<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * "Today" in the employee's own office timezone — never the server's UTC today.
 *
 * `APP_TIMEZONE` is UTC by rule (01-architecture.md), so a naive `Carbon::today()` is
 * UTC-today. Between 00:00 and 08:00 Asia/Manila that is still YESTERDAY locally, so an
 * employment record effective TODAY silently fails to appear in a payload that, in the same
 * response, already shows `EmployeeProfile::age` having rolled over — the two disagree about
 * what day it is. This mirrors that accessor's approach exactly: anchor to the employee's
 * CURRENT OFFICE timezone, falling back to Asia/Manila (the `offices.timezone` column's own
 * default) when the employee has no office yet.
 *
 * Resource-layer only. The pay engine never calls this: ComputeDailySummary and
 * PayrollExport always resolve against an explicit date they were given, never "now" — this
 * exists only for the HTTP resources that must choose "today" for themselves.
 */
final class EmployeeLocalToday
{
    public static function for(Employee $employee): Carbon
    {
        $timezone = $employee->currentOffice?->timezone ?? 'Asia/Manila';

        return Carbon::now($timezone)->startOfDay();
    }
}
