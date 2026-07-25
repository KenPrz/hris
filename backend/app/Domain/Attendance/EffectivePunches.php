<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Schedule\ScheduleResolver;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * The effective, non-annulled punch set for one employee's business day, as an ascending
 * list of integer minutes-from-that-date's-local-midnight — exactly the shape
 * PunchPairer::pair() consumes.
 *
 * "Business day" is deliberately not "calendar day". AttendanceMonth groups each punch by
 * ITS OWN local calendar date, which is correct for a read-only monthly ledger but wrong
 * for interpreting a night shift: a 22:00 in-punch and a 06:00-the-next-morning out-punch
 * would land on two different local dates and never pair. So this class instead gathers
 * everything inside date N's *shift window* — date N's local midnight through the
 * schedule's end minute, however far into date N+1 that runs — and expresses every punch
 * in that window as minutes from date N's midnight. A post-midnight out-punch therefore
 * comes back as e.g. 1800 (06:00 the next day), never wrapped down to 360 — the same
 * >1439 convention WorkInterval and PunchPairer already use for a cross-midnight
 * interval. The window's upper bound is inclusive: a punch recorded at exactly the
 * scheduled end (the ordinary case for a clock-out) must still count. A plain day shift
 * (scheduled end <= 24:00) or a rest day/no schedule collapses this to the ordinary
 * calendar-date window [00:00, 24:00].
 *
 * Approved corrections already materialize as a new attendance_logs row (M3.6's
 * adjustment flow) — this class does not merge or reinterpret anything, it only excludes
 * rows an attendance_annulments record has voided.
 */
final class EffectivePunches
{
    /** @return list<int> ascending minutes from $date's local midnight (may exceed 1439) */
    public static function forDate(Employee $employee, string $date): array
    {
        $timezone = $employee->currentOffice->timezone;
        $localMidnight = Carbon::parse($date, $timezone)->startOfDay();

        $windowStart = $localMidnight->copy()->setTimezone('UTC');
        $windowEnd = $localMidnight->copy()->addMinutes(self::windowMinutes($employee, $date))->setTimezone('UTC');

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $windowStart)
            ->where('punched_at', '<=', $windowEnd)
            ->orderBy('punched_at')
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        $annulledLogIds = AttendanceAnnulment::query()
            ->whereIn('attendance_log_id', $logs->pluck('id')->all())
            ->pluck('attendance_log_id')
            ->all();

        return $logs
            ->reject(fn (AttendanceLog $log): bool => in_array($log->id, $annulledLogIds, true))
            ->map(fn (AttendanceLog $log): int => self::minutesFromMidnight($log, $localMidnight))
            ->values()
            ->all();
    }

    /** The shift window's length in minutes: the calendar day, or the scheduled end if it runs later. */
    private static function windowMinutes(Employee $employee, string $date): int
    {
        $schedule = (new ScheduleResolver)->resolve($employee, $date);

        if ($schedule->isRestDay || $schedule->endMinute === null) {
            return 1440;
        }

        return max(1440, $schedule->endMinute);
    }

    /**
     * Minutes elapsed, as real time, from $localMidnight to the punch's instant — computed
     * from Unix timestamps so it stays correct regardless of which timezone either Carbon
     * instance currently carries.
     */
    private static function minutesFromMidnight(AttendanceLog $log, Carbon $localMidnight): int
    {
        return intdiv($log->punched_at->getTimestamp() - $localMidnight->getTimestamp(), 60);
    }
}
