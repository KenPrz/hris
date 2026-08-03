<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Exceptions\Domain\DuplicatePunchMinute;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Refuses a punch that would land in an office-local minute this employee already has a
 * punch in.
 *
 * Whole minutes, not seconds, because EffectivePunches truncates to the minute before
 * pairing: two punches forty seconds apart are the same minute downstream and collide in
 * PunchPairer. Nothing prevented that — a double-tap, two open tabs, or an HR admin
 * entering 08:00 twice through the deliberately non-idempotent manual route all produced
 * two rows inside one minute.
 *
 * Rejecting keeps the ledger append-only: nothing is edited or removed, the second write
 * simply never happens. And it is the only place that can still return an error to a
 * caller — the compute that discovers the collision runs in DB::afterCommit, after the
 * punch is durable, so by then the only options are a 500 or a broken day.
 *
 * A query-builder wrapper over Eloquent, the same shape as EmployeeScope/LeaveDayLookup —
 * domain-Eloquent is allowed here for the same reason it is allowed there.
 *
 * Must run inside RecordPunch's transaction under the employee row lock, or two concurrent
 * punches both find the minute free and both insert.
 */
final class PunchOrdering
{
    private function __construct() {}

    public static function assertOrderable(Employee $employee, CarbonInterface $punchedAt, string $timezone): void
    {
        $local = $punchedAt->copy()->setTimezone($timezone);
        $minuteStart = $local->copy()->startOfMinute();
        $minuteEnd = $minuteStart->copy()->addMinute();

        $exists = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $minuteStart->copy()->utc())
            ->where('punched_at', '<', $minuteEnd->copy()->utc())
            ->exists();

        if ($exists) {
            throw new DuplicatePunchMinute($local->format('Y-m-d H:i'));
        }
    }
}
