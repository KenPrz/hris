<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Models\LeaveLedger;
use Illuminate\Support\Facades\DB;

/**
 * HR manually crediting an employee's leave balance — one logged, append-only ledger
 * row. Balances are DERIVED (LeaveBalances::forEmployee sums the ledger), so there is no
 * balance column to touch here; writing the row IS the grant. A re-grant is a second row,
 * never an edit of the first — LeaveLedger has no updated_at and this action never
 * updates, only creates.
 */
final class GrantLeave
{
    public function execute(GrantLeaveInput $in): LeaveLedger
    {
        return DB::transaction(fn (): LeaveLedger => LeaveLedger::query()->create([
            'employee_id' => $in->employeeId,
            'leave_type_id' => $in->leaveTypeId,
            'entry_type' => 'credit',
            'minutes' => $in->minutes,
            'reason' => $in->reason,
            'source' => 'manual_grant',
            'created_by' => $in->actorId,
        ]));
    }
}
