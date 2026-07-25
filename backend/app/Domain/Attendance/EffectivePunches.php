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
 * The window's LOWER bound is not always date N's midnight, either: if date N-1 was a
 * cross-midnight shift that ran into date N, date N's window starts where date N-1's left
 * off, so consecutive night-shift windows tile instead of overlapping (see the M5b note
 * on windowStartMinutes()).
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

        $startMinute = self::windowStartMinutes($employee, $date);
        $windowStart = $localMidnight->copy()->addMinutes($startMinute)->setTimezone('UTC');
        $windowEnd = $localMidnight->copy()->addMinutes(self::windowMinutes($employee, $date))->setTimezone('UTC');

        // The start is exclusive when bounded by the previous day's window end: that exact
        // instant was already claimed inclusively by forDate(previous date)'s own '<=' end,
        // so counting it again here would double-claim the punch. An unbounded start (the
        // ordinary date-local-midnight case) stays inclusive — there is no earlier window to
        // have already claimed it.
        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', $startMinute > 0 ? '>' : '>=', $windowStart)
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

    /**
     * The shift window's length in minutes: the calendar day, or the scheduled end if it runs later.
     */
    private static function windowMinutes(Employee $employee, string $date): int
    {
        $schedule = (new ScheduleResolver)->resolve($employee, $date);

        if ($schedule->isRestDay || $schedule->endMinute === null) {
            return 1440;
        }

        return max(1440, $schedule->endMinute);
    }

    /**
     * The shift window's start, in minutes from date N's local midnight — 0 unless date
     * N-1 was a cross-midnight shift that ran into date N.
     *
     * M5b fix: when the SAME cross-midnight shift repeats on consecutive days, day N's
     * window (which runs past midnight) used to overlap day N+1's window at [00:00, N's
     * scheduled end - 1440], so a punch in that overlap was claimable by both dates. Fixed
     * by resolving date N-1's schedule and, if it ran past midnight (endMinute > 1440),
     * starting date N's window at (prevEndMinute - 1440) instead of 0 — the minutes before
     * that point already belong to date N-1's window and were returned by
     * forDate(dateN-1). forDate() also treats this bounded start as exclusive (see its
     * comment), so the exact boundary instant is claimed by exactly one of the two dates.
     * Consecutive windows now tile without overlap. A normal previous day, a rest day, or
     * no schedule leaves the start at 0 (unchanged behavior).
     */
    private static function windowStartMinutes(Employee $employee, string $date): int
    {
        $previousDate = Carbon::parse($date)->subDay()->toDateString();
        $previousSchedule = (new ScheduleResolver)->resolve($employee, $previousDate);

        if ($previousSchedule->isRestDay || $previousSchedule->endMinute === null) {
            return 0;
        }

        if ($previousSchedule->endMinute <= 1440) {
            return 0;
        }

        return $previousSchedule->endMinute - 1440;
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
