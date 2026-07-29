<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
| M8c Task 2: SetHrAdminOffices couples the hr_admin_offices pivot sync with the spatie
| 'HR Admin' role in one write, surfaced on the employee detail. Same is_system_admin
| gating as the rest of the admin group (OfficeCrudTest/EmployeeAdminTest). The 'HR Admin'
| role must exist for assignRole() to work — RbacSeeder normally creates it; findOrCreate
| here keeps this test independent of seeding order.
*/

beforeEach(fn () => Role::findOrCreate('HR Admin'));

it('grants HR-Admin access for an office, syncs the role, and audits the change', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $office = Office::factory()->create();
    $target = User::factory()->create();
    $employee = Employee::factory()->for($target)->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/admin/employees/{$employee->id}/hr-offices", [
        'office_ids' => [$office->id],
    ])->assertOk();

    expect($target->hrAdminOffices()->pluck('offices.id')->all())->toBe([$office->id])
        ->and($target->fresh()->hasRole('HR Admin'))->toBeTrue();

    expect($response->json('data.hr_admin_office_ids'))->toBe([$office->id])
        ->and($response->json('data.roles'))->toBe(['HR Admin']);

    $activity = Activity::query()
        ->where('subject_id', $target->id)
        ->where('description', 'hr_admin_offices_set')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->getExtraProperty('office_ids'))->toBe([$office->id]);
});

it('clears HR-Admin access and removes the role when office_ids is empty', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $office = Office::factory()->create();
    $target = User::factory()->create();
    $target->hrAdminOffices()->attach($office->id);
    $target->assignRole('HR Admin');
    $employee = Employee::factory()->for($target)->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/admin/employees/{$employee->id}/hr-offices", [
        'office_ids' => [],
    ])->assertOk();

    expect($target->hrAdminOffices()->pluck('offices.id')->all())->toBe([])
        ->and($target->fresh()->hasRole('HR Admin'))->toBeFalse();

    expect($response->json('data.hr_admin_office_ids'))->toBe([])
        ->and($response->json('data.roles'))->toBe([]);
});

it('refuses to grant HR-Admin access to an employee with no login', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $employee = Employee::factory()->create(['user_id' => null]);
    $office = Office::factory()->create();

    $this->postJson("/api/v1/admin/employees/{$employee->id}/hr-offices", [
        'office_ids' => [$office->id],
    ])->assertStatus(422)->assertJsonPath('error.code', 'employee_has_no_login');
});

it('rejects a nonexistent office id with a clean 422', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $employee = Employee::factory()->for(User::factory())->create();

    $this->postJson("/api/v1/admin/employees/{$employee->id}/hr-offices", [
        'office_ids' => [(string) Str::uuid7()],
    ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_reference');
});

it('forbids a non-admin from setting HR-Admin access', function (): void {
    Sanctum::actingAs(User::factory()->create());   // not a system admin
    $office = Office::factory()->create();
    $employee = Employee::factory()->for(User::factory())->create();

    $this->postJson("/api/v1/admin/employees/{$employee->id}/hr-offices", [
        'office_ids' => [$office->id],
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
