<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates a pending attendance-adjustment request and its 1:1 detail row, plus the
 * optional supporting attachment. This is the submit step only — approval and the
 * effect on the append-only punch ledger are later actions (see the M3.6 spec).
 */
final class SubmitAttendanceAdjustment
{
    public function execute(SubmitAttendanceAdjustmentInput $in): Request
    {
        return DB::transaction(function () use ($in): Request {
            $request = Request::query()->create([
                'type' => RequestType::AttendanceAdjustment,
                'employee_id' => $in->employeeId,
                'state' => RequestState::Pending,
                'note' => $in->note,
            ]);

            AttendanceAdjustmentDetail::query()->create([
                'request_id' => $request->id,
                'operation' => $in->operation,
                'target_log_id' => $in->targetLogId,
                'direction' => $in->direction,
                'punched_at' => $in->punchedAt,
            ]);

            if ($in->attachment !== null) {
                $request->addMedia($in->attachment->getRealPath())
                    ->usingFileName($in->attachment->getClientOriginalName())
                    ->toMediaCollection('attachment');
            }

            return $request->fresh('attendanceAdjustmentDetail');
        });
    }
}
