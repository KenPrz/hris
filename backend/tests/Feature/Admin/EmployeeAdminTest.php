<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets a system admin create an employee with a first employment record', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->for($org)->create();
    $dept = Department::factory()->for($office)->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-1001',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'employment' => [
            'effective_from' => '2026-02-01',
            'office_id' => $office->id,
            'department_id' => $dept->id,
            'employment_type' => 'probationary',
            'is_art82_exempt' => false,
            'base_rate_cents' => 61000,
        ],
    ])->assertCreated()->assertJsonPath('data.employee_no', 'EMP-1001');

    $employee = Employee::query()->where('employee_no', 'EMP-1001')->firstOrFail();
    // The first employment record populated the cache via RecordEmploymentChange.
    expect($employee->current_office_id)->toBe($office->id);
});

it('provisions a login for an existing employee', function (): void {
    $employee = Employee::factory()->create(['user_id' => null]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson("/api/v1/admin/employees/{$employee->id}/user", [
        'name' => 'New Hire',
        'email' => 'newhire@delsan.test',
        'password' => 'provisioned-pw',
    ])->assertCreated();

    expect($employee->refresh()->user)->not->toBeNull()
        ->and($employee->user->email)->toBe('newhire@delsan.test')
        ->and($employee->user->name)->toBe('New Hire');
});

it('refuses to provision a login for an employee who already has one', function (): void {
    $employee = Employee::factory()->for(User::factory())->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson("/api/v1/admin/employees/{$employee->id}/user", [
        'name' => 'New Hire',
        'email' => 'newhire@delsan.test',
        'password' => 'provisioned-pw',
    ])->assertStatus(422)->assertJsonPath('error.code', 'employee_already_has_login');
});

it('records an employment change for an existing employee over HTTP', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->for($org)->create();
    $dept = Department::factory()->for($office)->create();
    $employee = Employee::factory()->for($org)->create(['current_office_id' => null]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson("/api/v1/admin/employees/{$employee->id}/employment", [
        'effective_from' => '2026-02-01',
        'office_id' => $office->id,
        'department_id' => $dept->id,
        'employment_type' => 'regular',
        'is_art82_exempt' => false,
        'base_rate_cents' => 61000,
    ])->assertCreated();

    $employee->refresh();
    expect($employee->employmentRecords)->toHaveCount(1)
        ->and($employee->current_office_id)->toBe($office->id);
});

it('refuses a second employment change with the same effective_from', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->for($org)->create();
    $dept = Department::factory()->for($office)->create();
    $employee = Employee::factory()->for($org)->create(['current_office_id' => null]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $payload = [
        'effective_from' => '2026-02-01',
        'office_id' => $office->id,
        'department_id' => $dept->id,
        'employment_type' => 'regular',
        'is_art82_exempt' => false,
        'base_rate_cents' => 61000,
    ];

    $this->postJson("/api/v1/admin/employees/{$employee->id}/employment", $payload)
        ->assertCreated();

    $this->postJson("/api/v1/admin/employees/{$employee->id}/employment", $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'employment_record_exists');

    // The first change still stands: one record, cache populated from it.
    $employee->refresh();
    expect($employee->employmentRecords)->toHaveCount(1)
        ->and($employee->current_office_id)->toBe($office->id);
});

it('forbids a non-admin from creating employees', function (): void {
    $office = Office::factory()->create();
    Sanctum::actingAs(User::factory()->create());   // not a system admin

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-9999',
        'organization_id' => Organization::factory()->create()->id,
        'hired_at' => '2026-02-01',
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
