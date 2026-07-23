<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Models\AttendanceAnnulment;

/**
 * The single writer of attendance_annulments (arch-guarded). A void/amend supersedes a
 * punch by recording that it is annulled by an approved request — the original punch row
 * is never touched, preserving the append-only ledger. Ownership/existence/not-already-
 * annulled are validated by ApplyAttendanceAdjustment under the request lock; this is the
 * low-level append. Never updates or deletes. See docs/02-data-model.md.
 */
final class RecordAnnulment
{
    public function execute(string $attendanceLogId, string $requestId): AttendanceAnnulment
    {
        return AttendanceAnnulment::query()->create([
            'attendance_log_id' => $attendanceLogId,
            'request_id' => $requestId,
        ]);
    }
}
