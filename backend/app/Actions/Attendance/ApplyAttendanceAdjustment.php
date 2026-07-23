<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\InvalidAdjustmentTarget;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Request;

/**
 * Applies an approved attendance adjustment to the ledger. Called by ApproveRequest INSIDE
 * the request-row lock, so it assumes serialized approval. add → RecordPunch; void →
 * RecordAnnulment; amend → both. The append-only ledger is never mutated. See the spec.
 */
final class ApplyAttendanceAdjustment
{
    public function __construct(
        private readonly RecordPunch $recordPunch,
        private readonly RecordAnnulment $recordAnnulment,
    ) {}

    public function apply(Request $request, string $approverUserId): void
    {
        /** @var \App\Models\AttendanceAdjustmentDetail $detail */
        $detail = $request->attendanceAdjustmentDetail()->firstOrFail();

        $isVoid = $detail->operation === AdjustmentOperation::Void || $detail->operation === AdjustmentOperation::Amend;
        $isAdd = $detail->operation === AdjustmentOperation::Add || $detail->operation === AdjustmentOperation::Amend;

        if ($isVoid) {
            $this->assertAnnullable($detail->target_log_id, $request->employee_id);
            $this->recordAnnulment->execute($detail->target_log_id, $request->id);
        }

        if ($isAdd) {
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
        }
    }

    private function assertAnnullable(?string $targetLogId, string $requesterEmployeeId): void
    {
        $target = $targetLogId === null ? null : AttendanceLog::query()->find($targetLogId);

        if ($target === null || $target->employee_id !== $requesterEmployeeId) {
            throw new InvalidAdjustmentTarget('The punch to correct is missing or not yours.');
        }

        if (AttendanceAnnulment::query()->where('attendance_log_id', $targetLogId)->exists()) {
            throw new InvalidAdjustmentTarget('That punch is already annulled.');
        }
    }
}
