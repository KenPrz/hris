<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('lets a system admin update an employee name, leaving employee_no unchanged', function (): void {
    $employee = Employee::factory()->create([
        'employee_no' => 'EMP-3001',
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'name_suffix' => null,
    ]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->patchJson("/api/v1/admin/employees/{$employee->id}", [
        'first_name' => 'Juana',
        'middle_name' => 'Reyes',
        'last_name' => 'Santos',
        'name_suffix' => 'III',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Juana')
        ->assertJsonPath('data.middle_name', 'Reyes')
        ->assertJsonPath('data.last_name', 'Santos')
        ->assertJsonPath('data.name_suffix', 'III')
        ->assertJsonPath('data.full_name', 'Juana Reyes Santos III')
        ->assertJsonPath('data.employee_no', 'EMP-3001');

    $employee->refresh();
    expect($employee->first_name)->toBe('Juana')
        ->and($employee->middle_name)->toBe('Reyes')
        ->and($employee->last_name)->toBe('Santos')
        ->and($employee->name_suffix)->toBe('III')
        ->and($employee->full_name)->toBe('Juana Reyes Santos III')
        ->and($employee->employee_no)->toBe('EMP-3001');

    $activity = Activity::query()->where('log_name', 'employee')
        ->where('subject_id', $employee->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->toArray())->toHaveKey('attributes')
        ->and($activity->properties['attributes']['first_name'])->toBe('Juana');
});

it('forbids a non-admin from updating an employee name', function (): void {
    $employee = Employee::factory()->create(['first_name' => 'Juan']);
    Sanctum::actingAs(User::factory()->create());   // not a system admin

    $this->patchJson("/api/v1/admin/employees/{$employee->id}", [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

    expect($employee->refresh()->first_name)->toBe('Juan');
});

it('refuses to update an employee missing first_name', function (): void {
    $employee = Employee::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->patchJson("/api/v1/admin/employees/{$employee->id}", [
        'last_name' => 'Dela Cruz',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses to update an employee missing last_name', function (): void {
    $employee = Employee::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->patchJson("/api/v1/admin/employees/{$employee->id}", [
        'first_name' => 'Juana',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
