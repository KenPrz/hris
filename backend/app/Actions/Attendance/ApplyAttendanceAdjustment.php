<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\InvalidAdjustmentTarget;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies an approved attendance adjustment to the ledger. Called by ApproveRequest INSIDE
 * the request-row lock, so it assumes serialized approval. add → RecordPunch; void →
 * RecordAnnulment; amend → both. The append-only ledger is never mutated. See the spec.
 *
 * M5a Task 6: an add or amend already gets its day recomputed by RecordPunch itself (for
 * the corrected punch's own office-local date — see RecordPunch), since that action's
 * DB::afterCommit trigger fires regardless of whether it's called directly or, as here,
 * nested inside ApproveRequest's transaction. A pure void never calls RecordPunch, so
 * nothing else would recompute the day the annulled punch belonged to — this action
 * triggers that one explicitly, for the ANNULLED log's office-local date, also via
 * DB::afterCommit so a compute failure can't roll back a valid annulment (and tolerating
 * the same "no schedule configured yet" non-error as RecordPunch — see its docblock).
 *
 * An amend is a void + an add of a DIFFERENT log, and if the corrected punched_at crosses
 * the office-local date boundary, RecordPunch's trigger only recomputes the NEW date —
 * nothing recomputes the annulled punch's ORIGINAL date, which would otherwise keep
 * counting a punch that no longer exists in the effective ledger. So an amend ALSO
 * recomputes the old date explicitly, exactly like the pure-void path above, skipping the
 * extra compute only when the two dates are identical (the common same-day amend, where
 * RecordPunch's own recompute already covers it).
 */
final class ApplyAttendanceAdjustment
{
    public function __construct(
        private readonly RecordPunch $recordPunch,
        private readonly RecordAnnulment $recordAnnulment,
        private readonly ComputeDailySummary $computeDailySummary,
    ) {}

    public function apply(Request $request, string $approverUserId): void
    {
        /** @var \App\Models\AttendanceAdjustmentDetail $detail */
        $detail = $request->attendanceAdjustmentDetail()->firstOrFail();

        $isVoid = $detail->operation === AdjustmentOperation::Void || $detail->operation === AdjustmentOperation::Amend;
        $isAdd = $detail->operation === AdjustmentOperation::Add || $detail->operation === AdjustmentOperation::Amend;

        $target = null;
        if ($isVoid) {
            $target = $this->assertAnnullable($detail->target_log_id, $request->employee_id);
            $this->recordAnnulment->execute($detail->target_log_id, $request->id);
        }

        if ($isAdd) {
            // Recomputes the corrected punch's own day via RecordPunch's own trigger.
            $this->recordPunch->execute(new RecordPunchInput(
                employeeId: $request->employee_id,
                direction: $detail->direction,
                source: PunchSource::Adjustment,
                punchedAt: $detail->punched_at,
                recordedBy: $approverUserId,
                ipAddress: null,
                deviceId: null,
                geoLat: null,
                geoLng: null,
            ));

            if ($target !== null) {
                // Amend: RecordPunch above only recomputed the NEW date. Also recompute
                // the annulled punch's ORIGINAL office-local date — unless the correction
                // landed on the same date, in which case RecordPunch's own recompute
                // already covers it. See the class docblock.
                $employee = Employee::query()->findOrFail($request->employee_id);
                $oldDate = $target->punched_at->copy()->setTimezone($target->office->timezone)->format('Y-m-d');
                $newDate = $detail->punched_at->copy()
                    ->setTimezone($employee->currentOffice()->firstOrFail()->timezone)
                    ->format('Y-m-d');

                if ($oldDate !== $newDate) {
                    $this->scheduleRecompute($employee, $oldDate);
                }
            }
        } elseif ($target !== null) {
            // Pure void: the day the annulled punch belonged to needs recomputing, and
            // nothing else in this method will trigger it.
            $employee = Employee::query()->findOrFail($request->employee_id);
            $date = $target->punched_at->copy()->setTimezone($target->office->timezone)->format('Y-m-d');

            $this->scheduleRecompute($employee, $date);
        }
    }

    /**
     * Registers a recompute of (employee, date) to run once the OUTERMOST transaction
     * commits, so a compute failure can never roll back an already-durable
     * annulment/punch. Tolerates the same "no schedule configured yet" non-error
     * RecordPunch does — see its docblock — since M4's config isn't guaranteed yet for
     * every employee-day this path can reach.
     */
    private function scheduleRecompute(Employee $employee, string $date): void
    {
        DB::afterCommit(function () use ($employee, $date): void {
            try {
                $this->computeDailySummary->execute($employee, $date);
            } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate $e) {
                Log::info('Skipped daily summary compute after adjustment: no schedule configured.', [
                    'employee_id' => $employee->id, 'date' => $date, 'reason' => $e::class,
                ]);
            }
        });
    }

    private function assertAnnullable(?string $targetLogId, string $requesterEmployeeId): AttendanceLog
    {
        // lockForUpdate, not a plain find(): two DIFFERENT requests can both target the
        // SAME attendance_logs row, and each locks a different requests row in
        // ApproveRequest, so nothing above this serializes them against each other. This
        // row lock is what does: a second concurrent approval blocks here until the first
        // commits, then the exists() check below re-reads the now-committed annulment and
        // throws cleanly, before RecordAnnulment ever attempts a second insert that would
        // otherwise hit the unique(attendance_log_id) constraint as an uncaught 500.
        $target = $targetLogId === null ? null : AttendanceLog::query()->lockForUpdate()->find($targetLogId);

        if ($target === null || $target->employee_id !== $requesterEmployeeId) {
            throw new InvalidAdjustmentTarget('The punch to correct is missing or not yours.');
        }

        if (AttendanceAnnulment::query()->where('attendance_log_id', $targetLogId)->exists()) {
            throw new InvalidAdjustmentTarget('That punch is already annulled.');
        }

        return $target;
    }
}
