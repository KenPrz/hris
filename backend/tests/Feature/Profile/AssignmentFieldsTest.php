<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Profile\LaborType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records designation and labor type on the employment record', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();

    $record = app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2025-06-16',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: LaborType::Direct->value,
    ));

    expect($record->designation)->toBe('Backend Software Developer')
        ->and($record->labor_type)->toBe('direct');
});

it('keeps designation effective-dated rather than current-only', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();
    $action = app(RecordEmploymentChange::class);

    $base = [
        'employeeId' => $employee->id,
        'officeId' => $office->id,
        'departmentId' => $department->id,
        'reportsToId' => null,
        'employmentType' => 'regular',
        'isArt82Exempt' => false,
        'baseRateCents' => 3000000,
        'actorId' => null,
        'laborType' => 'direct',
    ];

    $action->execute(new RecordEmploymentChangeInput(...[...$base,
        'effectiveFrom' => '2025-06-16', 'designation' => 'Junior Developer']));
    $action->execute(new RecordEmploymentChangeInput(...[...$base,
        'effectiveFrom' => '2026-06-16', 'designation' => 'Backend Software Developer']));

    $designations = $employee->employmentRecords()
        ->orderBy('effective_from')->pluck('designation')->all();

    // Both survive. A promotion does not rewrite what last year's record said.
    expect($designations)->toBe(['Junior Developer', 'Backend Software Developer']);
});

it('exposes designation and labor type through the admin employee detail endpoint', function (): void {
    $office = Office::factory()->create(['region' => 'VII']);
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();
    $admin = User::factory()->create(['is_system_admin' => true]);

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2025-06-16',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: 'direct',
    ));

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.current_employment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.current_employment.labor_type', 'direct');

    expect($office->fresh()->region)->toBe('VII');
});
