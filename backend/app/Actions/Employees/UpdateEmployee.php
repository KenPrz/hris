<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Edits an employee's name only. employee_no is immutable (never in the fill array
 * below) and the current_* cache columns are never touched here — RecordEmploymentChange
 * is the sole writer of those, enforced by an arch test.
 */
final class UpdateEmployee
{
    public function execute(UpdateEmployeeInput $in): Employee
    {
        return DB::transaction(function () use ($in): Employee {
            $employee = Employee::query()->findOrFail($in->employeeId);

            $employee->fill([
                'first_name' => $in->firstName,
                'middle_name' => $in->middleName,
                'last_name' => $in->lastName,
                'name_suffix' => $in->nameSuffix,
            ])->save();

            return $employee;
        });
    }
}
