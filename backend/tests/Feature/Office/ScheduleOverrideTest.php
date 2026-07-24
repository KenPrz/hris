<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M4b Task 8: schedule-override list + create + update + delete — the per-date,
| per-employee exception (the rest-day-swap tool). An override targets an employee;
| scope is via the employee's current office. Mirrors ScheduleAssignmentTest's harness
| for the office-scoped 404-not-403 discipline.
*/

function overrideOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfOverride(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

// --- Create -----------------------------------------------------------------

it('creates a rest override', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $res = $this->postJson('/api/v1/office/schedule-overrides', [
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ])->assertCreated();

    expect($res->json('data.employee_id'))->toBe($employee->id)
        ->and($res->json('data.date'))->toBe('2026-08-15')
        ->and($res->json('data.is_rest'))->toBeTrue()
        ->and($res->json('data.start_minute'))->toBeNull()
        ->and($res->json('data.end_minute'))->toBeNull()
        ->and($res->json('data.break_minutes'))->toBeNull();

    // created_by is the audit trail this column exists for.
    $this->assertDatabaseHas('schedule_overrides', [
        'id' => $res->json('data.id'),
        'employee_id' => $employee->id,
        'is_rest' => true,
        'created_by' => $hr->id,
    ]);
});

it('creates a working override with hours', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $res = $this->postJson('/api/v1/office/schedule-overrides', [
        'employee_id' => $employee->id,
        'date' => '2026-08-16',
        'is_rest' => false,
        'start_minute' => 480,
        'end_minute' => 1080,
        'break_minutes' => 60,
        'note' => 'Covering for a rest-day swap',
    ])->assertCreated();

    expect($res->json('data.is_rest'))->toBeFalse()
        ->and($res->json('data.start_minute'))->toBe(480)
        ->and($res->json('data.end_minute'))->toBe(1080)
        ->and($res->json('data.break_minutes'))->toBe(60)
        ->and($res->json('data.note'))->toBe('Covering for a rest-day swap');

    $this->assertDatabaseHas('schedule_overrides', [
        'id' => $res->json('data.id'),
        'employee_id' => $employee->id,
        'created_by' => $hr->id,
    ]);
});

it('rejects a rest override carrying hours', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $this->postJson('/api/v1/office/schedule-overrides', [
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
        'start_minute' => 480,
        'end_minute' => 1080,
        'break_minutes' => 60,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('schedule_overrides', 0);
});

it('rejects a working override missing hours', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $this->postJson('/api/v1/office/schedule-overrides', [
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => false,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('schedule_overrides', 0);
});

it('accepts a cross-midnight working override (end beyond 1439)', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    // 22:00 -> 06:00 == start 1320, end 1800.
    $this->postJson('/api/v1/office/schedule-overrides', [
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => false,
        'start_minute' => 1320,
        'end_minute' => 1800,
        'break_minutes' => 60,
    ])->assertCreated();
});

it('rejects a duplicate override for the same employee and date', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $body = [
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ];

    $this->postJson('/api/v1/office/schedule-overrides', $body)->assertCreated();

    $this->postJson('/api/v1/office/schedule-overrides', $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'schedule_override_exists');

    $this->assertDatabaseCount('schedule_overrides', 1);
});

it('404s creating for an employee in an office not administered, identically to a fabricated employee', function (): void {
    $mine = overrideOffice();
    $other = overrideOffice();
    $hr = hrAdminOfOverride($mine);
    Sanctum::actingAs($hr);

    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);

    $body = fn (string $employeeId) => [
        'employee_id' => $employeeId,
        'date' => '2026-08-15',
        'is_rest' => true,
    ];

    $oos = $this->postJson('/api/v1/office/schedule-overrides', $body($theirEmployee->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/schedule-overrides', $body((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('schedule_overrides', 0);
});

// --- Update -------------------------------------------------------------

it('updates an existing override from rest to working', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $override = ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);

    $res = $this->patchJson("/api/v1/office/schedule-overrides/{$override->id}", [
        'is_rest' => false,
        'start_minute' => 540,
        'end_minute' => 1020,
        'break_minutes' => 60,
        'note' => 'Swapped to a working day',
    ])->assertOk();

    expect($res->json('data.is_rest'))->toBeFalse()
        ->and($res->json('data.start_minute'))->toBe(540)
        ->and($res->json('data.end_minute'))->toBe(1020)
        ->and($res->json('data.break_minutes'))->toBe(60)
        ->and($res->json('data.note'))->toBe('Swapped to a working day');

    $this->assertDatabaseHas('schedule_overrides', [
        'id' => $override->id,
        'is_rest' => false,
        'start_minute' => 540,
    ]);
});

it('rejects an update that violates is_rest XOR hours', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $override = ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);

    $this->patchJson("/api/v1/office/schedule-overrides/{$override->id}", [
        'is_rest' => true,
        'start_minute' => 540,
        'end_minute' => 1020,
        'break_minutes' => 60,
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('404s updating an override in an office not administered, identically to a fabricated override', function (): void {
    $mine = overrideOffice();
    $other = overrideOffice();
    $hr = hrAdminOfOverride($mine);
    Sanctum::actingAs($hr);

    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);
    $theirOverride = ScheduleOverride::create([
        'employee_id' => $theirEmployee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);

    $body = ['is_rest' => true];

    $oos = $this->patchJson("/api/v1/office/schedule-overrides/{$theirOverride->id}", $body)->assertStatus(404);
    $fake = $this->patchJson('/api/v1/office/schedule-overrides/'.Str::uuid7(), $body)->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});

// --- Delete -----------------------------------------------------------------

it('deletes an override', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $override = ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);

    $this->deleteJson("/api/v1/office/schedule-overrides/{$override->id}")->assertNoContent();

    $this->assertDatabaseMissing('schedule_overrides', ['id' => $override->id]);
});

it('404s deleting an override in an office not administered, identically to a fabricated override', function (): void {
    $mine = overrideOffice();
    $other = overrideOffice();
    $hr = hrAdminOfOverride($mine);
    Sanctum::actingAs($hr);

    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);
    $theirOverride = ScheduleOverride::create([
        'employee_id' => $theirEmployee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);

    $oos = $this->deleteJson("/api/v1/office/schedule-overrides/{$theirOverride->id}")->assertStatus(404);
    $fake = $this->deleteJson('/api/v1/office/schedule-overrides/'.Str::uuid7())->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('schedule_overrides', ['id' => $theirOverride->id]);
});

it('404s (not 500) updating or deleting an override whose employee has since lost their office', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    // An override created while the employee had an office; the office is then cleared.
    // The scope check must 404 on the null office, never hand a null to the string-typed
    // OfficeScope::administers and 500 on the uuid parser.
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $override = ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => '2026-08-15',
        'is_rest' => true,
    ]);
    $employee->update(['current_office_id' => null]);

    $this->patchJson("/api/v1/office/schedule-overrides/{$override->id}", [
        'is_rest' => true,
    ])->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    $this->deleteJson("/api/v1/office/schedule-overrides/{$override->id}")
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('schedule_overrides', ['id' => $override->id]);
});

// --- List -----------------------------------------------------------------

it("lists an employee's overrides filtered by month", function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $other = Employee::factory()->create(['current_office_id' => $office->id]);

    $inMonth = ScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-15', 'is_rest' => true]);
    ScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-09-01', 'is_rest' => true]); // different month
    ScheduleOverride::create(['employee_id' => $other->id, 'date' => '2026-08-15', 'is_rest' => true]); // different employee

    $res = $this->getJson('/api/v1/office/schedule-overrides?'.http_build_query([
        'office' => $office->id,
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.id'))->toBe($inMonth->id);
});

it('404s listing for an employee not in the given office', function (): void {
    $mine = overrideOffice();
    $other = overrideOffice();
    $hr = hrAdminOfOverride($mine);
    Sanctum::actingAs($hr);

    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);

    $query = fn (string $employeeId) => http_build_query([
        'office' => $mine->id,
        'employee' => $employeeId,
        'month' => '2026-08',
    ]);

    $oos = $this->getJson('/api/v1/office/schedule-overrides?'.$query($theirEmployee->id))->assertStatus(404);
    $fake = $this->getJson('/api/v1/office/schedule-overrides?'.$query((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});

it('404s listing for an office not administered', function (): void {
    $mine = overrideOffice();
    $other = overrideOffice();
    $hr = hrAdminOfOverride($mine);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $other->id]);

    $this->getJson('/api/v1/office/schedule-overrides?'.http_build_query([
        'office' => $other->id,
        'employee' => $employee->id,
        'month' => '2026-08',
    ]))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('rejects a malformed month', function (): void {
    $office = overrideOffice();
    $hr = hrAdminOfOverride($office);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $this->getJson('/api/v1/office/schedule-overrides?'.http_build_query([
        'office' => $office->id,
        'employee' => $employee->id,
        'month' => '2026-13',
    ]))->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
