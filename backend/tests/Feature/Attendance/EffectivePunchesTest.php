<?php

declare(strict_types=1);

use App\Domain\Attendance\EffectivePunches;
use App\Domain\Schedule\Weekday;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Every weekday working the given hours — no rest days, so any weekday in the tests works. */
function shiftTemplate(Office $office, int $start, int $end, int $break = 0): ShiftTemplate
{
    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => "Shift {$start}-{$end}"]);
    foreach (Weekday::cases() as $weekday) {
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id,
            'weekday' => $weekday,
            'is_rest' => false,
            'start_minute' => $start,
            'end_minute' => $end,
            'break_minutes' => $break,
        ]);
    }

    return $template;
}

function punch(Employee $employee, Office $office, string $localDateTime): AttendanceLog
{
    return AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'punched_at' => Carbon::parse($localDateTime, $office->timezone)->utc(),
    ]);
}

it('pairs a plain day shift as ascending minutes from local midnight', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $template = shiftTemplate($office, start: 480, end: 1020); // 08:00 -> 17:00
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    punch($employee, $office, '2026-08-03 08:00:00'); // Monday, in
    punch($employee, $office, '2026-08-03 17:00:00'); // Monday, out

    expect(EffectivePunches::forDate($employee, '2026-08-03'))->toBe([480, 1020]);
});

it('excludes an annulled log', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $template = shiftTemplate($office, start: 480, end: 1020);
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    punch($employee, $office, '2026-08-03 08:00:00'); // survives
    $voided = punch($employee, $office, '2026-08-03 17:00:00'); // annulled

    AttendanceAnnulment::create([
        'attendance_log_id' => $voided->id,
        'request_id' => Request::factory()->create()->id,
    ]);

    expect(EffectivePunches::forDate($employee, '2026-08-03'))->toBe([480]);
});

it('gathers a cross-midnight night shift into one business day, past 1439', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $template = shiftTemplate($office, start: 1320, end: 1800); // 22:00 -> 06:00 (+1 day)
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    punch($employee, $office, '2026-08-04 22:00:00'); // date N, in
    punch($employee, $office, '2026-08-05 06:00:00'); // date N+1, out — must still count for date N

    expect(EffectivePunches::forDate($employee, '2026-08-04'))->toBe([1320, 1800]);
});

it('excludes a punch outside the business-day window and keeps ascending order', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $template = shiftTemplate($office, start: 480, end: 1020); // 08:00 -> 17:00, plain day shift
    $office->update(['default_shift_template_id' => $template->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    punch($employee, $office, '2026-08-02 23:00:00'); // the previous day — must not leak in
    punch($employee, $office, '2026-08-03 17:00:00'); // out, seeded second on purpose
    punch($employee, $office, '2026-08-03 08:00:00'); // in, seeded after out

    expect(EffectivePunches::forDate($employee, '2026-08-03'))->toBe([480, 1020]);
});
