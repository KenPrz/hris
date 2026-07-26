<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Models\Employee;
use App\Models\LeaveLedger;

/**
 * Balances are DERIVED, never stored — there is no balance column anywhere. This is the
 * one place that turns the append-only leave_ledger into a per-type net: SUM(credit) -
 * SUM(debit), one grouped query, computed fresh on every call.
 *
 * This is a Domain service that touches Eloquent, which is allowed for a query/aggregate
 * builder — the M1 config-purity rule bars config() and facades from Domain, not the ORM
 * (same carve-out as EmployeeScope/OfficeScope/ApprovalQueues).
 */
final class LeaveBalances
{
    /** @return array<string, int> leave_type_id => net minutes */
    public static function forEmployee(Employee $employee): array
    {
        return LeaveLedger::query()
            ->where('employee_id', $employee->id)
            ->selectRaw("leave_type_id, SUM(CASE WHEN entry_type = 'credit' THEN minutes ELSE -minutes END) AS net")
            ->groupBy('leave_type_id')
            ->pluck('net', 'leave_type_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
