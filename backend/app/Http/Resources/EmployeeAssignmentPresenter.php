<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\ScheduleAssignment;

/**
 * The nine "Assignment" fields, rendered identically for the full and redacted resources —
 * one definition, so the two can never disagree about what an assignment looks like.
 *
 * Every value here is READ from existing M2/M4 tables; M10a writes none of them except
 * designation/labor_type (on employment_records) and region (on offices).
 */
final class EmployeeAssignmentPresenter
{
    public static function forEmployee(Employee $employee, ?EmploymentRecord $current): array
    {
        return [
            'designation' => $current?->designation,
            'business_unit' => $employee->currentDepartment?->name,
            'reports_to' => $employee->manager?->full_name,
            'employment_status' => $current?->employment_type,
            'location' => $employee->currentOffice?->name,
            'region' => $employee->currentOffice?->region,
            'labor_type' => $current?->labor_type,
            'hired_at' => $employee->hired_at?->toDateString(),
            'work_shift' => self::workShift($employee),
        ];
    }

    /**
     * The standing shift template's name ("8:00 Am To 6:00 Pm - Rest Sat & Sun"), resolved by
     * the same precedence App\Domain\Schedule\ScheduleResolver uses, MINUS the override layer:
     * employee assignment -> department assignment -> office default.
     *
     * Overrides are deliberately excluded. An override is a one-day exception ("on March 3 you
     * work a different shift"); a profile's "Work Shift" is the standing arrangement, and a
     * single date's exception is not it.
     *
     * This does NOT call ScheduleResolver::resolve(). That method throws EmployeeHasNoOffice
     * and OfficeHasNoDefaultTemplate — correct for the compute engine, fatal for a profile
     * read, which must render for a half-onboarded employee rather than 500. Every branch here
     * returns null instead.
     */
    private static function workShift(Employee $employee): ?string
    {
        // Office-local today, not the server's UTC today — see EmployeeLocalToday.
        $today = EmployeeLocalToday::for($employee)->toDateString();

        $assignment = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $today)
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment === null && $employee->current_department_id !== null) {
            $assignment = ScheduleAssignment::query()
                ->where('department_id', $employee->current_department_id)
                ->whereDate('effective_from', '<=', $today)
                ->orderByDesc('effective_from')
                ->first();
        }

        if ($assignment !== null) {
            return $assignment->shiftTemplate?->name;
        }

        return $employee->currentOffice?->defaultShiftTemplate?->name;
    }
}
