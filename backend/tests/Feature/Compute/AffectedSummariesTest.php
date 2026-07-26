<?php

declare(strict_types=1);

use App\Domain\Compute\AffectedSummaries;
use App\Domain\Pay\DayType;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

/*
| Task 5: the affected-set resolvers. AffectedSummaries maps a config change to the set of
| EXISTING daily_attendance_summaries it invalidates, as (employee_id, date) pairs. Every
| resolver here queries existing summary rows only — a config change never fabricates a
| pair for a summary that has not been computed yet. computeOffice/computeEmployee come
| from support.php (shared with the other Compute feature tests).
*/

function seedAffectedSummary(Employee $employee, Office $office, string $date): DailyAttendanceSummary
{
    return DailyAttendanceSummary::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'office_id' => $office->id,
        'day_type' => DayType::Ordinary,
        'is_rest_day' => false,
        'scheduled_minutes' => 540,
        'is_art82_exempt' => false,
        'rule_version_id' => null,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'status' => 'computed',
        'is_incomplete' => false,
        'computed_at' => now(),
    ]);
}

/** @param list<array{employee_id: string, date: string}> $pairs @return list<array{employee_id: string, date: string}> */
function sortPairs(array $pairs): array
{
    usort($pairs, fn (array $a, array $b): int => [$a['employee_id'], $a['date']] <=> [$b['employee_id'], $b['date']]);

    return $pairs;
}

it('forHoliday scopes by BOTH office and the given dates', function (): void {
    $manila = computeOffice();
    $cebu = computeOffice();
    $eManila = computeEmployee($manila);
    $eCebu = computeEmployee($cebu);

    seedAffectedSummary($eManila, $manila, '2026-08-21');   // Manila, target date -> included
    seedAffectedSummary($eManila, $manila, '2026-08-22');   // Manila, other date -> excluded
    seedAffectedSummary($eCebu, $cebu, '2026-08-21');       // Cebu, same date -> excluded (wrong office)

    $pairs = AffectedSummaries::forHoliday($manila->id, ['2026-08-21']);

    expect($pairs)->toBe([['employee_id' => $eManila->id, 'date' => '2026-08-21']]);
});

it('forPayRule includes only date >= effectiveFrom', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);

    seedAffectedSummary($employee, $office, '2026-05-31'); // before effectiveFrom -> excluded
    seedAffectedSummary($employee, $office, '2026-06-01'); // exactly effectiveFrom -> included
    seedAffectedSummary($employee, $office, '2026-06-15'); // after effectiveFrom -> included

    $pairs = AffectedSummaries::forPayRule('2026-06-01');

    expect(sortPairs($pairs))->toBe(sortPairs([
        ['employee_id' => $employee->id, 'date' => '2026-06-01'],
        ['employee_id' => $employee->id, 'date' => '2026-06-15'],
    ]));
});

it('forShiftTemplate includes an employee directly assigned the template (employee-target)', function (): void {
    $office = computeOffice();
    $assigned = computeEmployee($office);
    $unrelated = computeEmployee($office);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Custom']);
    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $assigned->id,
        'department_id' => null,
        'effective_from' => '2026-01-01',
    ]);

    seedAffectedSummary($assigned, $office, '2026-08-25');
    seedAffectedSummary($unrelated, $office, '2026-08-25'); // not assigned this template -> excluded

    $pairs = AffectedSummaries::forShiftTemplate($template->id);

    expect($pairs)->toBe([['employee_id' => $assigned->id, 'date' => '2026-08-25']]);
});

it('forShiftTemplate includes employees in a department assigned the template (department-target)', function (): void {
    $office = computeOffice();
    $deptEmployee = computeEmployee($office);
    $unrelated = computeEmployee($office);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'DeptCustom']);
    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => null,
        'department_id' => $deptEmployee->current_department_id,
        'effective_from' => '2026-01-01',
    ]);

    seedAffectedSummary($deptEmployee, $office, '2026-08-26');
    seedAffectedSummary($unrelated, $office, '2026-08-26'); // different department -> excluded

    $pairs = AffectedSummaries::forShiftTemplate($template->id);

    expect($pairs)->toBe([['employee_id' => $deptEmployee->id, 'date' => '2026-08-26']]);
});

it('forShiftTemplate includes employees whose office default is the template (office-target)', function (): void {
    $manila = computeOffice();
    $cebu = computeOffice();
    $eManila = computeEmployee($manila);
    $eCebu = computeEmployee($cebu);

    seedAffectedSummary($eManila, $manila, '2026-08-21');
    seedAffectedSummary($eCebu, $cebu, '2026-08-21'); // different office, different default template -> excluded

    $pairs = AffectedSummaries::forShiftTemplate($manila->default_shift_template_id);

    expect($pairs)->toBe([['employee_id' => $eManila->id, 'date' => '2026-08-21']]);
});

it('forShiftTemplate unions all sources without duplicating a pair', function (): void {
    // An employee reachable through more than one source (here: employee-target AND their
    // office's default happens to be the SAME template) must still appear exactly once.
    $office = computeOffice();
    $employee = computeEmployee($office);

    ScheduleAssignment::create([
        'shift_template_id' => $office->default_shift_template_id,
        'employee_id' => $employee->id,
        'department_id' => null,
        'effective_from' => '2026-01-01',
    ]);

    seedAffectedSummary($employee, $office, '2026-08-21');

    $pairs = AffectedSummaries::forShiftTemplate($office->default_shift_template_id);

    expect($pairs)->toBe([['employee_id' => $employee->id, 'date' => '2026-08-21']]);
});

it('forEmployee returns only that employee\'s existing summaries', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $other = computeEmployee($office);

    seedAffectedSummary($employee, $office, '2026-08-21');
    seedAffectedSummary($employee, $office, '2026-08-22');
    seedAffectedSummary($other, $office, '2026-08-21');

    $pairs = AffectedSummaries::forEmployee($employee->id);

    expect(sortPairs($pairs))->toBe(sortPairs([
        ['employee_id' => $employee->id, 'date' => '2026-08-21'],
        ['employee_id' => $employee->id, 'date' => '2026-08-22'],
    ]));
});

it('forOffice returns only that office\'s existing summaries', function (): void {
    $manila = computeOffice();
    $cebu = computeOffice();
    $eManila = computeEmployee($manila);
    $eCebu = computeEmployee($cebu);

    seedAffectedSummary($eManila, $manila, '2026-08-21');
    seedAffectedSummary($eManila, $manila, '2026-08-22');
    seedAffectedSummary($eCebu, $cebu, '2026-08-21');

    $pairs = AffectedSummaries::forOffice($manila->id);

    expect(sortPairs($pairs))->toBe(sortPairs([
        ['employee_id' => $eManila->id, 'date' => '2026-08-21'],
        ['employee_id' => $eManila->id, 'date' => '2026-08-22'],
    ]));
});

it('each resolver returns [] for a scope with no existing summaries', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    // No summaries seeded at all.

    expect(AffectedSummaries::forHoliday($office->id, ['2026-08-21']))->toBe([])
        ->and(AffectedSummaries::forPayRule('2026-01-01'))->toBe([])
        ->and(AffectedSummaries::forShiftTemplate((string) Str::uuid7()))->toBe([])
        ->and(AffectedSummaries::forEmployee($employee->id))->toBe([])
        ->and(AffectedSummaries::forOffice($office->id))->toBe([]);
});
