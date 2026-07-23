<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\Request;
use Illuminate\Support\Carbon;
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
                // Normalise to a true UTC instant before the timestamptz write. A client may
                // submit an offset-bearing time (e.g. 08:00+08:00); Eloquent's datetime cast
                // formats without the offset, which would silently store the wrong instant —
                // the same trap RecordPunch guards against with ->utc().
                'punched_at' => $in->punchedAt !== null
                    ? Carbon::parse($in->punchedAt)->utc()
                    : null,
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
