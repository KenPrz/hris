<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding: inserts the immutable Employee row and, when a first employment block is
 * given, records it through RecordEmploymentChange in the same transaction — the one
 * action allowed to write the current_* cache. CreateEmployee itself never touches those
 * columns; an arch guard enforces that RecordEmploymentChange is the sole writer.
 */
final class CreateEmployee
{
    public function __construct(private readonly RecordEmploymentChange $recordChange) {}

    public function execute(CreateEmployeeInput $in): Employee
    {
        return DB::transaction(function () use ($in): Employee {
            $employee = Employee::query()->create([
                'employee_no' => $in->employeeNo,
                'organization_id' => $in->organizationId,
                'hired_at' => $in->hiredAt,
            ]);

            if ($in->firstEmployment !== null) {
                // Re-point the input at the new employee id, then record it — this fills
                // the current_* cache through the one action allowed to write it.
                $this->recordChange->execute(new RecordEmploymentChangeInput(
                    employeeId: $employee->id,
                    effectiveFrom: $in->firstEmployment->effectiveFrom,
                    officeId: $in->firstEmployment->officeId,
                    departmentId: $in->firstEmployment->departmentId,
                    reportsToId: $in->firstEmployment->reportsToId,
                    employmentType: $in->firstEmployment->employmentType,
                    isArt82Exempt: $in->firstEmployment->isArt82Exempt,
                    baseRateCents: $in->firstEmployment->baseRateCents,
                    actorId: $in->actorId,
                ));
            }

            return $employee->refresh();
        });
    }
}
