<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\EffectivePunches;
use App\Domain\Attendance\PunchOrdering;
use App\Domain\Attendance\PunchVerifier;
use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single writer of attendance_logs (arch-guarded). Snapshots the employee's current
 * office, resolves the punch time (supplied for a manual entry, server-now for
 * self-service), verifies, and appends the row. Never updates — a correction is a new row.
 * See the M3 spec.
 *
 * After the write commits, (re)computes that employee-day's summary (M5a Task 6) — for BOTH
 * the punch's own office-local date (the date AttendanceMonth groups it under) and the day
 * before it, because a post-midnight punch belongs to the previous business day's shift
 * window. Registered via DB::afterCommit from inside this transaction, so it fires only once
 * the OUTERMOST transaction commits: whether this is a direct self-service/manual punch, or
 * RecordPunch running nested inside ApplyAttendanceAdjustment/ApproveRequest's transaction
 * for an add or amend. A compute failure therefore can never roll back an already-durable
 * punch.
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
            // lockForUpdate so two concurrent punches for the same employee serialize through
            // PunchOrdering below rather than both finding the minute free and both inserting.
            // The same row lock ComputeDailySummary, ApproveRequest and CloseCutoff take.
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            // Snapshot the office the punch belongs to now, so a later transfer never
            // reinterprets this punch's timezone or geofence.
            $office = $employee->currentOffice()->firstOrFail();

            PunchOrdering::assertOrderable($employee, $in->punchedAt ?? now(), $office->timezone);

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

            // Which business date owns this punch is EffectivePunches' rule, not this
            // action's: a post-midnight punch belongs to the PREVIOUS day's shift window,
            // which is what makes a 22:00-06:00 shift one day rather than two halves.
            //
            // Deriving the date from the punch's own local date instead left every night
            // shift's first day permanently unpaired — the in-punch computed day N (one
            // punch, incomplete, worked 0) and the out-punch computed day N+1, whose window
            // correctly excludes it, so day N was never revisited by the punch that completed
            // it. Because CloseCutoff refuses to close over an incomplete in-period day, an
            // office running night shifts could never close a cutoff at all.
            //
            // Asking EffectivePunches rather than computing both candidate dates blindly:
            // blindly would also create a summary for the day BEFORE every ordinary day-shift
            // punch, whose window ended at midnight and never contained it.
            DB::afterCommit(function () use ($employee, $log): void {
                try {
                    $dates = EffectivePunches::datesOwning($employee, $log->punched_at);
                } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate $e) {
                    // See the class docblock: no schedule configured for this employee-day
                    // yet is an expected, non-fatal state, not a compute failure. Logged so
                    // that once M4 config is expected everywhere, a still-unschedulable
                    // employee is diagnosable rather than silently summary-less. Resolving the
                    // owning date needs the schedule too, so this catch covers both steps.
                    Log::info('Skipped daily summary compute after punch: no schedule configured.', [
                        'employee_id' => $employee->id,
                        'punched_at' => $log->punched_at->toIso8601String(),
                        'reason' => $e::class,
                    ]);

                    return;
                }

                foreach ($dates as $date) {
                    $this->computeDailySummary->execute($employee, $date);
                }
            });

            return $log;
        });
    }
}
