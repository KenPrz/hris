<?php

declare(strict_types=1);

/*
| Shared seed helpers for the Compute feature tests (ComputeDailySummaryTest,
| RecomputeDayTest, ...). Deliberately NOT named *Test.php so PHPUnit's directory
| discovery never picks it up as a test file — the same convention already used by
| tests/Feature/Attendance/Support/*.php. Each consuming test file pulls this in with
| `require_once __DIR__.'/support.php';` rather than redeclaring these functions itself:
| PHP fatally errors on a duplicate top-level function definition the moment both files
| are loaded in the same process (e.g. a full `pest tests/Feature` run), so the helpers
| live in exactly one place.
*/

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Pay\DayType;
use App\Domain\Schedule\Weekday;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\PayRule;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Support\Carbon;

if (! function_exists('computeOffice')) {
    /** Mon-Fri 08:00-18:00 (480-1080 minutes, 60m break), Sat/Sun rest — set as the office default. */
    function computeOffice(): Office
    {
        $office = Office::factory()->create(['timezone' => 'Asia/Manila']);

        $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Standard']);
        foreach (Weekday::cases() as $wd) {
            $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
            ShiftTemplateDay::create([
                'shift_template_id' => $template->id,
                'weekday' => $wd,
                'is_rest' => $rest,
                'start_minute' => $rest ? null : 480,
                'end_minute' => $rest ? null : 1080,
                'break_minutes' => $rest ? null : 60,
            ]);
        }
        $office->update(['default_shift_template_id' => $template->id]);

        return $office;
    }
}

if (! function_exists('computeEmployee')) {
    /** An employee with a resolvable EmploymentRecord (office/department/art82) effective from 2026-01-01. */
    function computeEmployee(Office $office, bool $art82Exempt = false): Employee
    {
        $department = Department::factory()->create(['office_id' => $office->id]);

        $employee = Employee::factory()->create([
            'organization_id' => $office->organization_id,
            'current_office_id' => $office->id,
            'current_department_id' => $department->id,
        ]);

        EmploymentRecord::factory()->create([
            'employee_id' => $employee->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'effective_from' => '2026-01-01',
            'is_art82_exempt' => $art82Exempt,
        ]);

        return $employee;
    }
}

if (! function_exists('seedPayRule')) {
    /**
     * A pay-rules version at exactly the statutory floor, effective from 2026-01-01, with
     * per-day-type overrides applied on top (keyed by DayType::value, each value
     * [workedBp, workedRestBp, unworkedBp], any cell omitted keeps the floor).
     *
     * @param  array<string, array{0: int, 1: int, 2: int}>  $overrides
     */
    function seedPayRule(string $effectiveFrom = '2026-01-01', array $overrides = []): PayRule
    {
        $floors = config('hris.pay_floors');

        $rule = PayRule::create([
            'effective_from' => $effectiveFrom,
            'overtime_ordinary_bp' => $floors['overtime_ordinary'],
            'overtime_premium_bp' => $floors['overtime_premium'],
            'night_diff_bp' => $floors['night_diff'],
        ]);

        foreach (DayType::cases() as $dayType) {
            [$workedBp, $workedRestBp] = $floors['worked'][$dayType->value];
            $unworkedBp = $floors['unworked'][$dayType->value];

            if (isset($overrides[$dayType->value])) {
                [$workedBp, $workedRestBp, $unworkedBp] = $overrides[$dayType->value];
            }

            $rule->dayRates()->create([
                'day_type' => $dayType->value,
                'worked_bp' => $workedBp,
                'worked_rest_bp' => $workedRestBp,
                'unworked_bp' => $unworkedBp,
            ]);
        }

        return $rule;
    }
}

if (! function_exists('recordManualPunch')) {
    /** Records a manual, self-verifying punch (no IP) at local $time on $date in $office's timezone. */
    function recordManualPunch(Employee $employee, Office $office, string $date, string $time, PunchDirection $direction): void
    {
        app(RecordPunch::class)->execute(new RecordPunchInput(
            employeeId: $employee->id,
            direction: $direction,
            source: PunchSource::Manual,
            punchedAt: Carbon::parse("{$date} {$time}", $office->timezone)->utc(),
            recordedBy: null,
            ipAddress: null,
            deviceId: null,
            geoLat: null,
            geoLng: null,
        ));
    }
}
