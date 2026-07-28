<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('persists all four name fields and returns them with full_name on the resource', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $response = $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-2001',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'first_name' => 'Maria',
        'middle_name' => 'Reyes',
        'last_name' => 'Santos',
        'name_suffix' => 'III',
    ])->assertCreated();

    $response
        ->assertJsonPath('data.first_name', 'Maria')
        ->assertJsonPath('data.middle_name', 'Reyes')
        ->assertJsonPath('data.last_name', 'Santos')
        ->assertJsonPath('data.name_suffix', 'III')
        ->assertJsonPath('data.full_name', 'Maria Reyes Santos III');

    $employee = Employee::query()->where('employee_no', 'EMP-2001')->firstOrFail();
    expect($employee->first_name)->toBe('Maria')
        ->and($employee->middle_name)->toBe('Reyes')
        ->and($employee->last_name)->toBe('Santos')
        ->and($employee->name_suffix)->toBe('III')
        ->and($employee->full_name)->toBe('Maria Reyes Santos III');
});

it('persists a name with no middle name or suffix and collapses the whitespace in full_name', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $response = $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-2002',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
    ])->assertCreated();

    $response
        ->assertJsonPath('data.first_name', 'Juan')
        ->assertJsonPath('data.middle_name', null)
        ->assertJsonPath('data.last_name', 'Dela Cruz')
        ->assertJsonPath('data.name_suffix', null)
        ->assertJsonPath('data.full_name', 'Juan Dela Cruz');
});

it('refuses to create an employee missing first_name', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-2003',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'last_name' => 'Dela Cruz',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses to create an employee missing last_name', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-2004',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'first_name' => 'Juan',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
