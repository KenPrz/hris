<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M4b Task 9: GET /office/schedule/resolved — ScheduleResolver over HTTP. One row per
| date of the month, built by calling ScheduleResolver::resolve for every date; the
| employee is scoped by current_office_id being an office the caller administers, so an
| out-of-scope employee 404s identically to a fabricated one (the same discipline as every
| other office-scoped endpoint on this branch). OfficeHasNoDefaultTemplate and
| EmployeeHasNoOffice are left to propagate — the envelope maps them to 422.
*/

function resolvedOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfResolved(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

/** @param array<int, array<string, mixed>> $days */
function resolvedTemplateWithDays(Office $office, array $days): ShiftTemplate
{
    $template = ShiftTemplate::query()->create(['office_id' => $office->id, 'name' => 'Template']);

    foreach ($days as $day) {
        $template->days()->create($day);
    }

    return $template;
}

/** Mon-Fri 08:00-18:00 (break 60), Sat/Sun rest. @return array<int, array<string, mixed>> */
function mondayToFridayDays(): array
{
    return collect(range(0, 6))->map(fn (int $wd) => $wd < 5
        ? ['weekday' => $wd, 'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60]
        : ['weekday' => $wd, 'is_rest' => true])->all();
}

it('resolves every date of the month for an employee-assigned template', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    $template = resolvedTemplateWithDays($office, mondayToFridayDays());
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);

    $res = $this->getJson('/api/v1/office/schedule/resolved?'.http_build_query([
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertOk();

    $data = $res->json('data');

    // August 2026 has 31 days.
    expect($data)->toHaveCount(31);

    foreach (array_keys($data) as $date) {
        expect($date)->toMatch('/^2026-08-\d{2}$/');
    }

    // 2026-08-03 is a Monday.
    expect($data['2026-08-03'])->toMatchArray([
        'is_rest' => false,
        'start_minute' => 480,
        'end_minute' => 1080,
        'break_minutes' => 60,
        'scheduled_minutes' => 540,
        'source' => 'employee',
    ]);

    // 2026-08-01 is a Saturday.
    expect($data['2026-08-01'])->toMatchArray([
        'is_rest' => true,
        'start_minute' => null,
        'end_minute' => null,
        'break_minutes' => null,
        'scheduled_minutes' => 0,
        'source' => 'employee',
    ]);
});

it('resolves a cross-midnight night shift with end_minute beyond 1439', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    $days = collect(range(0, 6))->map(fn (int $wd) => ['weekday' => $wd, 'is_rest' => true])->all();
    // Tuesday (weekday index 1): 17:00 -> 03:00, i.e. start 1020, end 1620.
    $days[1] = ['weekday' => 1, 'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 60];

    $template = resolvedTemplateWithDays($office, $days);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);

    $res = $this->getJson('/api/v1/office/schedule/resolved?'.http_build_query([
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertOk();

    // 2026-08-04 is a Tuesday.
    expect($res->json('data.2026-08-04'))->toMatchArray([
        'is_rest' => false,
        'start_minute' => 1020,
        'end_minute' => 1620,
        'break_minutes' => 60,
        'scheduled_minutes' => 540,
        'source' => 'employee',
    ]);
});

it('422s with office_has_no_default_template when nothing covers the employee', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    // No assignment, no department, and the office has no default_shift_template_id.
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $this->getJson('/api/v1/office/schedule/resolved?'.http_build_query([
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertStatus(422)->assertJsonPath('error.code', 'office_has_no_default_template');
});

it('reflects an override, flipping the source for that date', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    $template = resolvedTemplateWithDays($office, mondayToFridayDays());
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);

    // 2026-08-03 is a Monday, normally working 08:00-18:00 — override it to rest.
    ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => '2026-08-03',
        'is_rest' => true,
    ]);

    $res = $this->getJson('/api/v1/office/schedule/resolved?'.http_build_query([
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertOk();

    expect($res->json('data.2026-08-03'))->toMatchArray([
        'is_rest' => true,
        'scheduled_minutes' => 0,
        'source' => 'override',
    ]);
});

it('404s for an employee not in an office administered by the caller, identically to a fabricated employee', function (): void {
    $mine = resolvedOffice();
    $other = resolvedOffice();
    $hr = hrAdminOfResolved($mine);
    Sanctum::actingAs($hr);

    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);

    $query = fn (string $employeeId) => http_build_query([
        'employee' => $employeeId,
        'month' => '2026-08',
    ]);

    $oos = $this->getJson('/api/v1/office/schedule/resolved?'.$query($theirEmployee->id))->assertStatus(404);
    $fake = $this->getJson('/api/v1/office/schedule/resolved?'.$query((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});

it('404s for an employee with no office (not a 500 from a null uuid), identically to a fabricated employee', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    // A real employee with no current_office_id: administrable by nobody. The controller
    // must 404 (not hand a null-derived '' to the uuid parser and 500).
    $unassigned = Employee::factory()->create(['current_office_id' => null]);

    $query = fn (string $employeeId) => http_build_query(['employee' => $employeeId, 'month' => '2026-08']);

    $noOffice = $this->getJson('/api/v1/office/schedule/resolved?'.$query($unassigned->id))->assertStatus(404);
    $fake = $this->getJson('/api/v1/office/schedule/resolved?'.$query((string) Str::uuid7()))->assertStatus(404);

    $noOffice->assertExactJson($fake->json());
    $noOffice->assertJsonPath('error.code', 'not_found');
});

it('rejects a malformed month', function (): void {
    $office = resolvedOffice();
    $hr = hrAdminOfResolved($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $this->getJson('/api/v1/office/schedule/resolved?'.http_build_query([
        'employee' => $employee->id,
        'month' => '2026-13',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
