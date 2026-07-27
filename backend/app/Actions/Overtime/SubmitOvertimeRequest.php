<?php

declare(strict_types=1);

namespace App\Actions\Overtime;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates an overtime pre-authorization request and its 1:1 detail row — the submit step
 * only, mirroring SubmitLeaveRequest. Overtime is single-hop, so the request always starts
 * `pending`: there is no managerless auto-advance (a single-hop pending request is already
 * actionable by office HR at /office, unlike a two-hop leave that would otherwise stall).
 * Minutes are validated positive in the controller before this is called; this only persists.
 */
final class SubmitOvertimeRequest
{
    public function execute(SubmitOvertimeRequestInput $in): Request
    {
        return DB::transaction(function () use ($in): Request {
            $request = Request::query()->create([
                'type' => RequestType::Overtime,
                'employee_id' => $in->employeeId,
                'state' => RequestState::Pending,
                'note' => $in->note,
            ]);

            OvertimeDetail::query()->create([
                'request_id' => $request->id,
                'date' => $in->date,
                'minutes' => $in->minutes,
            ]);

            return $request->fresh(['overtimeDetail']);
        });
    }
}
