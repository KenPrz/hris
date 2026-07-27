<?php

declare(strict_types=1);

use App\Domain\Leave\LeaveDays;
use App\Domain\Schedule\Weekday;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Mon-Fri 08:00-18:00 (60m break), Sat/Sun rest — same shape as ScheduleResolverTest's. */
function leaveDaysWeekdayTemplate(Office $office): ShiftTemplate
{
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080, 'break_minutes' => $rest ? null : 60]);
    }

    return $t;
}

it('returns the 5 weekdays for a Mon-Fri schedule over a Mon-Sun range', function (): void {
    $office = Office::factory()->create();
    $template = leaveDaysWeekdayTemplate($office);
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    // 2026-08-03 is a Monday; 2026-08-09 is the following Sunday.
    $days = LeaveDays::scheduledWorkingDays($employee, '2026-08-03', '2026-08-09');

    expect($days)->toBe([
        '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07',
    ]);
});

it('excludes a per-date override rest day from an otherwise-working range', function (): void {
    $office = Office::factory()->create();
    $template = leaveDaysWeekdayTemplate($office);
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    ScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-04', 'is_rest' => true]);

    $days = LeaveDays::scheduledWorkingDays($employee, '2026-08-03', '2026-08-05');

    expect($days)->toBe(['2026-08-03', '2026-08-05']);
});

it('returns an empty list when the whole range is rest days', function (): void {
    $office = Office::factory()->create();
    $template = leaveDaysWeekdayTemplate($office);
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $days = LeaveDays::scheduledWorkingDays($employee, '2026-08-08', '2026-08-09'); // Sat-Sun

    expect($days)->toBe([]);
});
