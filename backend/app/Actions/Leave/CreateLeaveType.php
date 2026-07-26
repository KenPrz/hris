<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * Creates one leave-type row for an office. The office-scope check (is this office one the
 * caller administers?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on LeaveType records the `created` event itself,
 * with the causer resolved from the authenticated guard automatically.
 *
 * Unlike CreateHoliday, no recompute is dispatched — leave-type config is a catalog entry,
 * not something any already-computed attendance summary depends on.
 */
final class CreateLeaveType
{
    public function execute(CreateLeaveTypeInput $in): LeaveType
    {
        return DB::transaction(function () use ($in): LeaveType {
            return LeaveType::query()->create([
                'office_id' => $in->officeId,
                'name' => $in->name,
                'code' => $in->code,
                'is_paid' => $in->isPaid,
                'requires_attachment' => $in->requiresAttachment,
                'deducts_balance' => $in->deductsBalance,
                'is_cash_convertible' => $in->isCashConvertible,
                'max_carryover_minutes' => $in->maxCarryoverMinutes,
                'is_active' => $in->isActive,
            ]);
        });
    }
}
