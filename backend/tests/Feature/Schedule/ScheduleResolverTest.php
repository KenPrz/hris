<?php
declare(strict_types=1);

use App\Domain\Schedule\ScheduleResolver;
use App\Domain\Schedule\ScheduleSource;
use App\Domain\Schedule\Weekday;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Mon-Fri 08:00-18:00 (60m break), Sat/Sun rest. */
function weekdayTemplate(Office $office, string $name = 'Office'): ShiftTemplate {
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => $name]);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080, 'break_minutes' => $rest ? null : 60]);
    }
    return $t;
}

function resolver(): ScheduleResolver { return app(ScheduleResolver::class); }

it('resolves a weekday from the office default when nothing else is assigned', function (): void {
    $office = Office::factory()->create();
    $t = weekdayTemplate($office);
    $office->update(['default_shift_template_id' => $t->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    $mon = resolver()->resolve($emp, '2026-08-03'); // Monday
    expect($mon->isRestDay)->toBeFalse()
        ->and($mon->startMinute)->toBe(480)->and($mon->endMinute)->toBe(1080)
        ->and($mon->scheduledMinutes)->toBe(540) // 600 span - 60 break
        ->and($mon->source)->toBe(ScheduleSource::OfficeDefault);

    $sat = resolver()->resolve($emp, '2026-08-08'); // Saturday
    expect($sat->isRestDay)->toBeTrue()->and($sat->scheduledMinutes)->toBe(0);
});

it('prefers an employee assignment over the office default', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $assigned = weekdayTemplate($office, 'Assigned');
    ScheduleAssignment::create(['shift_template_id' => $assigned->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Employee);
});

it('prefers a department assignment over the office default, and an employee assignment over the department', function (): void {
    $office = Office::factory()->create();
    $dept = Department::factory()->create(['office_id' => $office->id]);
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => $dept->id]);
    ScheduleAssignment::create(['shift_template_id' => weekdayTemplate($office, 'Dept')->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Department);
    ScheduleAssignment::create(['shift_template_id' => weekdayTemplate($office, 'Emp')->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Employee);
});

it('uses the greatest effective_from that is <= the date, ignoring future assignments', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $aug = weekdayTemplate($office, 'Aug'); $sep = weekdayTemplate($office, 'Sep');
    ScheduleAssignment::create(['shift_template_id' => $aug->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    ScheduleAssignment::create(['shift_template_id' => $sep->id, 'employee_id' => $emp->id, 'effective_from' => '2026-09-01']);
    expect(resolver()->resolve($emp, '2026-08-17')->startMinute)->not->toBeNull(); // Aug applies (a Monday, not a weekend)
    // resolve for a date before ANY assignment -> falls through to office default
    expect(resolver()->resolve($emp, '2026-07-15')->source)->toBe(ScheduleSource::OfficeDefault);
});

it('lets a per-date override win over everything, for both rest and custom hours', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    // Make a normally-working Monday a rest day.
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-03', 'is_rest' => true]);
    $r = resolver()->resolve($emp, '2026-08-03');
    expect($r->isRestDay)->toBeTrue()->and($r->source)->toBe(ScheduleSource::Override);
    // Make a normally-rest Saturday a working day.
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-08', 'is_rest' => false,
        'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60]);
    $s = resolver()->resolve($emp, '2026-08-08');
    expect($s->isRestDay)->toBeFalse()->and($s->scheduledMinutes)->toBe(420)->and($s->source)->toBe(ScheduleSource::Override);
});

it('resolves a cross-midnight night shift with end_minute beyond 1439', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Night']);
    foreach (Weekday::cases() as $wd) {
        ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => $wd, 'is_rest' => false,
            'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 0]); // 17:00 -> 03:00
    }
    $office->update(['default_shift_template_id' => $t->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $r = resolver()->resolve($emp, '2026-08-04'); // Tuesday
    expect($r->endMinute)->toBe(1620)->and($r->scheduledMinutes)->toBe(600);
});

it('throws when the office has no default template', function (): void {
    $office = Office::factory()->create(['default_shift_template_id' => null]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    expect(fn () => resolver()->resolve($emp, '2026-08-03'))->toThrow(OfficeHasNoDefaultTemplate::class);
});

it('throws when the employee has no office', function (): void {
    $emp = Employee::factory()->create(['current_office_id' => null]);
    expect(fn () => resolver()->resolve($emp, '2026-08-03'))->toThrow(EmployeeHasNoOffice::class);
});
