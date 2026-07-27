<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Requests\RequestEffect;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * The overtime effect: on the request's single approval (overtime is single-hop, so the
 * one approval IS the final hop) it writes NOTHING — the approved request plus its
 * overtime_details.minutes IS the authorization the compute engine reads
 * (OvertimeAuthorizationLookup). Unlike LeaveEffect there is no ledger, no balance, no
 * lock: nothing to overdraw. It only enqueues a recompute over the authorized date so
 * ComputeDailySummary re-prices that day under the now-approved cap — via DB::afterCommit,
 * since a recompute-enqueue failure must never roll back an already-durable approval
 * (mirrors LeaveEffect / CreateHoliday).
 */
final class OvertimeEffect implements RequestEffect
{
    public function applyOnApproval(Request $request, string $approverUserId): void
    {
        $detail = $request->overtimeDetail;

        DB::afterCommit(function () use ($request, $detail): void {
            RecomputeRange::dispatch(
                collect([['employee_id' => $request->employee_id, 'date' => $detail->date->toDateString()]]),
                RecomputeTrigger::Overtime,
                $request->id,
                "Overtime request {$request->id} approved for employee {$request->employee_id}",
            );
        });
    }
}
