<?php

declare(strict_types=1);

namespace App\Domain\Employment;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use Carbon\CarbonInterface;

/**
 * "What was true for this employee on this date." The pay engine (M5) computes a past
 * period by reading the record whose range covers each day, so a later promotion never
 * changes what a closed period was paid.
 *
 * effective_to is not stored: the covering record is simply the latest one whose
 * effective_from is on or before the date.
 */
final class EmploymentResolver
{
    public static function on(Employee $employee, CarbonInterface $date): ?EmploymentRecord
    {
        return EmploymentRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }
}
