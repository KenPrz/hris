<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\PunchVerifier;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of attendance_logs (arch-guarded). Snapshots the employee's current
 * office, resolves the punch time (supplied for a manual entry, server-now for
 * self-service), verifies, and appends the row. Never updates — a correction is a new row.
 * See the M3 spec.
 */
final class RecordPunch
{
    public function __construct(private readonly PunchVerifier $verifier) {}

    public function execute(RecordPunchInput $in): AttendanceLog
    {
        return DB::transaction(function () use ($in): AttendanceLog {
            $employee = Employee::query()->findOrFail($in->employeeId);

            // Snapshot the office the punch belongs to now, so a later transfer never
            // reinterprets this punch's timezone or geofence.
            $office = $employee->currentOffice()->firstOrFail();

            $result = $this->verifier->verify($office, $in->ipAddress, $in->geoLat, $in->geoLng);

            return AttendanceLog::query()->create([
                'employee_id' => $employee->id,
                'office_id' => $office->id,
                'punched_at' => $in->punchedAt ?? now(),
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
        });
    }
}
