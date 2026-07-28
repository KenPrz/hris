<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets a system admin list employees company-wide', function (): void {
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();
    $withUser = Employee::factory()->for(User::factory())->create(['current_office_id' => $officeA->id]);
    $withoutUser = Employee::factory()->create(['user_id' => null, 'current_office_id' => $officeB->id]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $response = $this->getJson('/api/v1/admin/employees')->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId->has($withUser->id))->toBeTrue()
        ->and($byId->has($withoutUser->id))->toBeTrue()
        ->and($byId[$withUser->id]['has_user'])->toBeTrue()
        ->and($byId[$withUser->id]['full_name'])->toBe($withUser->full_name)
        ->and($byId[$withoutUser->id]['has_user'])->toBeFalse();
});

it('narrows the employee list to a single office via ?office=', function (): void {
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();
    $inA = Employee::factory()->create(['current_office_id' => $officeA->id]);
    $inB = Employee::factory()->create(['current_office_id' => $officeB->id]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $response = $this->getJson("/api/v1/admin/employees?office={$officeA->id}")->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($inA->id)
        ->and($ids)->not->toContain($inB->id);
});

it('shows an employee with their current employment record', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->for($org)->create();
    $dept = Department::factory()->for($office)->create();
    $employee = Employee::factory()->for($org)->create(['current_office_id' => null, 'user_id' => null]);

    (new RecordEmploymentChange())->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2026-02-01',
        officeId: $office->id,
        departmentId: $dept->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 61000,
        actorId: null,
    ));

    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.employee_no', $employee->employee_no)
        ->assertJsonPath('data.full_name', $employee->full_name)
        ->assertJsonPath('data.has_user', false)
        ->assertJsonPath('data.current_employment.office_id', $office->id)
        ->assertJsonPath('data.current_employment.department_id', $dept->id)
        ->assertJsonPath('data.current_employment.employment_type', 'regular')
        ->assertJsonPath('data.current_employment.is_art82_exempt', false)
        ->assertJsonPath('data.current_employment.base_rate_cents', 61000)
        ->assertJsonPath('data.current_employment.effective_from', '2026-02-01');
});

it('shows null current_employment for an employee with no employment record yet', function (): void {
    $employee = Employee::factory()->create(['current_office_id' => null]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.current_employment', null);
});

it('forbids a non-admin from listing employees via the admin profiler route', function (): void {
    Sanctum::actingAs(User::factory()->create());   // not a system admin

    $this->getJson('/api/v1/admin/employees')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('forbids a non-admin from showing an employee via the admin profiler route', function (): void {
    $employee = Employee::factory()->create();
    Sanctum::actingAs(User::factory()->create());   // not a system admin

    $this->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});
