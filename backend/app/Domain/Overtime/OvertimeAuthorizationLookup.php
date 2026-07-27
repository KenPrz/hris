<?php

declare(strict_types=1);

namespace App\Domain\Overtime;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;

/**
 * How many overtime minutes are APPROVED (final-hop; overtime is single-hop, so `approved`
 * is the only terminal-approved state) for one employee on one business date — the cap
 * DailyComputation applies as min(actual_overtime, approved). The one fact the compute
 * engine needs; it never queries the database itself (see DailyComputation's purity
 * contract), so ComputeDailySummary resolves this and hands it in on DailyComputationInput.
 *
 * A query-builder wrapper over Eloquent, the same shape as LeaveDayLookup/EmployeeScope —
 * domain-Eloquent is allowed here for the same reason it is allowed there. Returns 0 when
 * nothing is approved: the strict model — unauthorized overtime caps at zero and reads as
 * unpaid excess.
 */
final class OvertimeAuthorizationLookup
{
    private function __construct() {}

    public static function approvedMinutesFor(Employee $employee, string $date): int
    {
        return (int) Request::query()
            ->where('employee_id', $employee->id)
            ->where('type', RequestType::Overtime)
            ->where('state', RequestState::Approved)
            ->join('overtime_details', 'overtime_details.request_id', '=', 'requests.id')
            ->whereDate('overtime_details.date', $date)
            ->sum('overtime_details.minutes');
    }
}
