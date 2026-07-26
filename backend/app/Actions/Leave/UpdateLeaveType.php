<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * Updates one leave-type row. The office-scope check (does the caller administer this
 * leave type's office?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on LeaveType records the `updated` event itself,
 * with the causer resolved from the authenticated guard automatically.
 *
 * No delete action exists — a type is retired by an update that sets is_active=false.
 * No recompute is dispatched here either, for the same reason as CreateLeaveType.
 */
final class UpdateLeaveType
{
    public function execute(LeaveType $leaveType, UpdateLeaveTypeInput $in): LeaveType
    {
        return DB::transaction(function () use ($leaveType, $in): LeaveType {
            $leaveType->update([
                'name' => $in->name,
                'code' => $in->code,
                'is_paid' => $in->isPaid,
                'requires_attachment' => $in->requiresAttachment,
                'deducts_balance' => $in->deductsBalance,
                'is_cash_convertible' => $in->isCashConvertible,
                'max_carryover_minutes' => $in->maxCarryoverMinutes,
                'is_active' => $in->isActive,
            ]);

            return $leaveType;
        });
    }
}
