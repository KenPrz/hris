<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use Illuminate\Support\Collection;

/**
 * Groups raw punches by office-local calendar date — no pairing, no business-day logic.
 * Each punch's local date is computed in ITS snapshot office's timezone, so a transfer
 * never reinterprets an old punch. A cross-midnight punch lands on its own local date;
 * interpretation is M5's job. See the M3 spec.
 */
final class AttendanceMonth
{
    /**
     * @param  Collection<int, AttendanceLog>  $logs  each with `office` loaded
     * @return array<string, list<array<string, mixed>>>
     */
    public static function group(Collection $logs): array
    {
        return $logs
            ->sortBy('punched_at')
            ->groupBy(fn (AttendanceLog $log): string => $log->punched_at
                ->setTimezone($log->office->timezone)
                ->format('Y-m-d'))
            ->map(fn (Collection $day) => $day
                ->map(fn (AttendanceLog $log) => AttendanceLogResource::make($log)->resolve())
                ->values()
                ->all())
            ->sortKeys()
            ->all();
    }
}
