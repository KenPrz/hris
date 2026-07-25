<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchVerifier;
use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of attendance_logs (arch-guarded). Snapshots the employee's current
 * office, resolves the punch time (supplied for a manual entry, server-now for
 * self-service), verifies, and appends the row. Never updates — a correction is a new row.
 * See the M3 spec.
 *
 * After the write commits, (re)computes that employee-day's summary (M5a Task 6) — for the
 * punch's OWN office-local date, the same date AttendanceMonth groups it under. Registered
 * via DB::afterCommit from inside this transaction, so it fires only once the OUTERMOST
 * transaction commits: whether this is a direct self-service/manual punch, or RecordPunch
 * running nested inside ApplyAttendanceAdjustment/ApproveRequest's transaction for an add
 * or amend. A compute failure therefore can never roll back an already-durable punch.
 *
 * EmployeeHasNoOffice / OfficeHasNoDefaultTemplate — both raised by ScheduleResolver — are
 * the one deliberate exception to "don't swallow": before M4 ships holiday/shift config,
 * "no schedule configured yet" is the NORMAL state for most employees, exactly like
 * ComputeDailySummary already tolerates "no pay_rules version yet" by computing without
 * persisting priced lines rather than erroring. Treating the schedule-config gap as fatal
 * here would turn every punch/adjustment into a 422 until M4 lands, which is not this
 * task's call to make. Anything else — a genuine compute bug — still propagates, uncaught.
 */
final class RecordPunch
{
    public function __construct(
        private readonly PunchVerifier $verifier,
        private readonly ComputeDailySummary $computeDailySummary,
    ) {}

    public function execute(RecordPunchInput $in): AttendanceLog
    {
        return DB::transaction(function () use ($in): AttendanceLog {
            $employee = Employee::query()->findOrFail($in->employeeId);

            // Snapshot the office the punch belongs to now, so a later transfer never
            // reinterprets this punch's timezone or geofence.
            $office = $employee->currentOffice()->firstOrFail();

            $result = $this->verifier->verify($office, $in->ipAddress, $in->geoLat, $in->geoLng);

            $log = AttendanceLog::query()->create([
                'employee_id' => $employee->id,
                'office_id' => $office->id,
                'punched_at' => ($in->punchedAt ?? now())->utc(),
                'direction' => $in->direction,
                'source' => $in->source,
                'verification' => $result->status,
                'flag_reason' => $result->reason,
                'recorded_by' => $in->recordedBy,
                'ip_address' => $in->ipAddress,
                'device_id' => $in->deviceId,
                'geo_lat' => $in->geoLat,
                'geo_lng' => $in->geoLng,
            ]);

            // ->copy() so the timezone conversion below never mutates $log->punched_at
            // itself — callers (and existing tests) read that attribute back as UTC.
            $date = $log->punched_at->copy()->setTimezone($office->timezone)->format('Y-m-d');

            DB::afterCommit(function () use ($employee, $date): void {
                try {
                    $this->computeDailySummary->execute($employee, $date);
                } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate) {
                    // See the class docblock: no schedule configured for this employee-day
                    // yet is an expected, non-fatal state, not a compute failure.
                }
            });

            return $log;
        });
    }
}
