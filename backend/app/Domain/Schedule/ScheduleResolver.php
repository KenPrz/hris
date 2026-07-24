<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplateDay;

/**
 * The single interface M5's compute engine calls to find out what an employee was
 * scheduled to work on a given day. Walks override -> employee assignment -> department
 * assignment -> office default template, in that order, and returns the first hit. Pure
 * read: no transaction, no writes.
 */
final class ScheduleResolver
{
    public function resolve(Employee $employee, string $date): ResolvedSchedule
    {
        $override = ScheduleOverride::query()
            ->where('employee_id', $employee->id)->whereDate('date', $date)->first();
        if ($override !== null) {
            return $this->fromShape($override->is_rest, $override->start_minute, $override->end_minute,
                $override->break_minutes, ScheduleSource::Override);
        }

        $employeeAssignment = $this->latestAssignment(
            ScheduleAssignment::query()->where('employee_id', $employee->id), $date);
        if ($employeeAssignment !== null) {
            return $this->fromTemplate($employeeAssignment->shift_template_id, $date, ScheduleSource::Employee);
        }

        if ($employee->current_department_id !== null) {
            $deptAssignment = $this->latestAssignment(
                ScheduleAssignment::query()->where('department_id', $employee->current_department_id), $date);
            if ($deptAssignment !== null) {
                return $this->fromTemplate($deptAssignment->shift_template_id, $date, ScheduleSource::Department);
            }
        }

        if ($employee->current_office_id === null) {
            throw new EmployeeHasNoOffice($employee->id);
        }
        $office = $employee->currentOffice;
        $defaultId = $office?->default_shift_template_id;
        if ($defaultId === null) {
            throw new OfficeHasNoDefaultTemplate($employee->current_office_id);
        }
        return $this->fromTemplate($defaultId, $date, ScheduleSource::OfficeDefault);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<ScheduleAssignment> $query untyped: the
     *     domain layer must not reference Illuminate\Database in code, only in a docblock.
     */
    private function latestAssignment($query, string $date): ?ScheduleAssignment
    {
        return $query->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    private function fromTemplate(string $templateId, string $date, ScheduleSource $source): ResolvedSchedule
    {
        $weekday = Weekday::from(self::weekdayIndex($date));
        /** @var ShiftTemplateDay $day */
        $day = ShiftTemplateDay::query()->where('shift_template_id', $templateId)->where('weekday', $weekday->value)->sole();
        return $this->fromShape($day->is_rest, $day->start_minute, $day->end_minute, $day->break_minutes, $source);
    }

    private function fromShape(bool $isRest, ?int $start, ?int $end, ?int $break, ScheduleSource $source): ResolvedSchedule
    {
        return $isRest
            ? ResolvedSchedule::rest($source)
            : ResolvedSchedule::working((int) $start, (int) $end, (int) $break, $source);
    }

    /** 0=Monday..6=Sunday from a Y-m-d string, matching Weekday and the frontend. */
    public static function weekdayIndex(string $date): int
    {
        // Carbon: dayOfWeekIso is 1=Mon..7=Sun; subtract 1 for 0=Mon..6=Sun.
        return (int) \Illuminate\Support\Carbon::parse($date)->dayOfWeekIso - 1;
    }
}
