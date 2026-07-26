<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;

/**
 * Maps a config change to the set of EXISTING daily_attendance_summaries it invalidates,
 * as (employee_id, date) pairs shaped for RecomputeRange::dispatch(). Each resolver here
 * queries existing summary rows only — a config change never fabricates a pair for a
 * summary that has not been computed yet; there is nothing to recompute until
 * ComputeDailySummary has run once for that (employee, date).
 *
 * Over-inclusion is safe: ComputeDailySummary is idempotent, so forShiftTemplate/forOffice/
 * forEmployee deliberately return ALL of the affected employees' existing summaries rather
 * than trying to narrow to the exact dates a change touches. Completeness (recomputing a
 * summary that turns out unaffected) is harmless; the reverse — silently skipping one that
 * WAS affected — is the actual bug this class exists to avoid. Only forHoliday and
 * forPayRule narrow by date, because the config itself names an exact date set (a holiday
 * calendar entry) or a clean lower bound (a pay rule's effective_from) — narrowing there
 * costs nothing and is exact, not an approximation.
 *
 * Queries Eloquent directly via Model::query(), the same shape as the Domain\Scope
 * precedent (EmployeeScope/OfficeScope) and Domain\Schedule\ScheduleResolver /
 * Domain\Attendance\EffectivePunches: no typed Illuminate\Database\Eloquent\Builder import
 * anywhere in real code (only in docblocks), so the Arch suite's "domain layer is
 * framework-agnostic" rule — which bars Illuminate\Database as a hard dependency — never
 * trips, and this class needs no addition to that test's ignoring() list the way
 * EmployeeScope/OfficeScope did.
 */
final class AffectedSummaries
{
    /**
     * @param  list<string>  $dates
     * @return list<array{employee_id: string, date: string}>
     */
    public static function forHoliday(string $officeId, array $dates): array
    {
        return self::pairs(
            DailyAttendanceSummary::query()
                ->where('office_id', $officeId)
                ->whereIn('date', $dates)
        );
    }

    /** @return list<array{employee_id: string, date: string}> */
    public static function forPayRule(string $effectiveFrom): array
    {
        return self::pairs(
            DailyAttendanceSummary::query()->whereDate('date', '>=', $effectiveFrom)
        );
    }

    /**
     * "On the template" unions three sources: an employee directly assigned the template,
     * an employee whose current department is assigned the template, and an employee
     * whose current office's default_shift_template_id is the template. Over-inclusion is
     * safe (see class docblock), so this does not filter by ScheduleAssignment's
     * effective_from — an employee who was EVER assigned this template is included.
     *
     * @return list<array{employee_id: string, date: string}>
     */
    public static function forShiftTemplate(string $templateId): array
    {
        $employeeIds = ScheduleAssignment::query()
            ->where('shift_template_id', $templateId)
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        $departmentIds = ScheduleAssignment::query()
            ->where('shift_template_id', $templateId)
            ->whereNotNull('department_id')
            ->pluck('department_id');

        if ($departmentIds->isNotEmpty()) {
            $employeeIds = $employeeIds->merge(
                Employee::query()->whereIn('current_department_id', $departmentIds)->pluck('id')
            );
        }

        $officeIds = Office::query()->where('default_shift_template_id', $templateId)->pluck('id');

        if ($officeIds->isNotEmpty()) {
            $employeeIds = $employeeIds->merge(
                Employee::query()->whereIn('current_office_id', $officeIds)->pluck('id')
            );
        }

        $ids = $employeeIds->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return self::pairs(
            DailyAttendanceSummary::query()->whereIn('employee_id', $ids)
        );
    }

    /** @return list<array{employee_id: string, date: string}> */
    public static function forEmployee(string $employeeId): array
    {
        return self::pairs(
            DailyAttendanceSummary::query()->where('employee_id', $employeeId)
        );
    }

    /** @return list<array{employee_id: string, date: string}> */
    public static function forOffice(string $officeId): array
    {
        return self::pairs(
            DailyAttendanceSummary::query()->where('office_id', $officeId)
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DailyAttendanceSummary>  $query  untyped:
     *     the domain layer must not reference Illuminate\Database in code, only in a docblock.
     * @return list<array{employee_id: string, date: string}>
     */
    private static function pairs($query): array
    {
        return $query->get(['employee_id', 'date'])
            ->map(fn (DailyAttendanceSummary $s): array => [
                'employee_id' => $s->employee_id,
                'date' => $s->date->toDateString(),
            ])
            ->all();
    }
}
