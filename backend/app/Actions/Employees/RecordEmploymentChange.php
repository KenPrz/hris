<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of the current_* cache. Inserts an effective-dated history row and,
 * when that row is the latest for the employee, updates the three cache columns — in one
 * transaction, so the ledger and its cache can never disagree.
 *
 * A back-dated correction updates history but leaves the cache alone: "current" means the
 * latest effective date, not the most recently entered row. See docs/02-data-model.md.
 */
final class RecordEmploymentChange
{
    public function execute(RecordEmploymentChangeInput $in): EmploymentRecord
    {
        return DB::transaction(function () use ($in): EmploymentRecord {
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            $record = EmploymentRecord::query()->create([
                'employee_id' => $in->employeeId,
                'effective_from' => $in->effectiveFrom,
                'office_id' => $in->officeId,
                'department_id' => $in->departmentId,
                'reports_to_id' => $in->reportsToId,
                'employment_type' => $in->employmentType,
                'is_art82_exempt' => $in->isArt82Exempt,
                'base_rate_cents' => $in->baseRateCents,
                'created_by' => $in->actorId,
            ]);

            // Only advance the cache if this is now the latest effective date.
            $latest = EmploymentRecord::query()
                ->where('employee_id', $in->employeeId)
                ->orderByDesc('effective_from')
                ->first();

            if ($latest !== null && $latest->id === $record->id) {
                $employee->update([
                    'current_office_id' => $in->officeId,
                    'current_department_id' => $in->departmentId,
                    'current_reports_to_id' => $in->reportsToId,
                ]);
            }

            return $record;
        });
    }
}
