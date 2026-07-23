<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function changeInput(Employee $employee, Office $office, Department $dept, array $overrides = []): RecordEmploymentChangeInput
{
    return new RecordEmploymentChangeInput(...array_merge([
        'employeeId' => $employee->id,
        'effectiveFrom' => '2026-01-01',
        'officeId' => $office->id,
        'departmentId' => $dept->id,
        'reportsToId' => null,
        'employmentType' => 'regular',
        'isArt82Exempt' => false,
        'baseRateCents' => 61000,
        'actorId' => null,
    ], $overrides));
}

it('writes a history row and updates the cache in one go', function (): void {
    $office = Office::factory()->create();
    $dept = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create(['current_office_id' => null]);

    app(RecordEmploymentChange::class)->execute(changeInput($employee, $office, $dept));

    $employee->refresh();
    expect($employee->employmentRecords)->toHaveCount(1)
        ->and($employee->current_office_id)->toBe($office->id)
        ->and($employee->current_department_id)->toBe($dept->id);
});

it('moves the cache forward on a promotion to a later effective date', function (): void {
    $office = Office::factory()->create();
    $deptA = Department::factory()->for($office)->create();
    $deptB = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create();

    $action = app(RecordEmploymentChange::class);
    $action->execute(changeInput($employee, $office, $deptA, ['effectiveFrom' => '2026-01-01']));
    $action->execute(changeInput($employee, $office, $deptB, ['effectiveFrom' => '2026-06-01']));

    expect($employee->refresh()->current_department_id)->toBe($deptB->id)
        ->and($employee->employmentRecords)->toHaveCount(2);
});

it('does not move the cache backward on a back-dated correction', function (): void {
    // A correction filed for a PAST date must not overwrite the current state.
    $office = Office::factory()->create();
    $deptCurrent = Department::factory()->for($office)->create();
    $deptOld = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create();

    $action = app(RecordEmploymentChange::class);
    $action->execute(changeInput($employee, $office, $deptCurrent, ['effectiveFrom' => '2026-06-01']));
    $action->execute(changeInput($employee, $office, $deptOld, ['effectiveFrom' => '2026-01-01']));

    // The cache still reflects the June (latest) row, not the back-dated January one.
    expect($employee->refresh()->current_department_id)->toBe($deptCurrent->id);
});
