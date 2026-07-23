<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates an employee with no login account', function (): void {
    // A punch-only worker: employee record, no user_id.
    $employee = Employee::factory()->create(['user_id' => null]);

    expect($employee->user_id)->toBeNull()
        ->and($employee->user)->toBeNull()
        ->and($employee->employee_no)->toBeString();
});

it('links an employee to an optional user', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create();

    expect($employee->user->is($user))->toBeTrue();
});

it('resolves manager and reports through the current_reports_to_id cache', function (): void {
    $manager = Employee::factory()->create();
    $report = Employee::factory()->create(['current_reports_to_id' => $manager->id]);

    expect($report->manager->is($manager))->toBeTrue()
        ->and($manager->reports->pluck('id')->all())->toContain($report->id);
});

it('records an effective-dated employment row', function (): void {
    $employee = Employee::factory()->create();
    $record = EmploymentRecord::factory()->for($employee)->create([
        'effective_from' => '2026-01-01',
        'is_art82_exempt' => true,
        'base_rate_cents' => 5_000_00,
    ]);

    expect($record->effective_from->toDateString())->toBe('2026-01-01')
        ->and($record->is_art82_exempt)->toBeTrue()
        ->and($record->base_rate_cents)->toBe(500000)
        ->and($employee->employmentRecords)->toHaveCount(1);
});

it('enforces employee_no uniqueness', function (): void {
    Employee::factory()->create(['employee_no' => 'EMP-0001']);

    expect(fn () => Employee::factory()->create(['employee_no' => 'EMP-0001']))
        ->toThrow(QueryException::class);
});

it('makes hr_admin_offices a composite-key grant', function (): void {
    $user = User::factory()->create();
    $office = Office::factory()->create();

    DB::table('hr_admin_offices')->insert(['user_id' => $user->id, 'office_id' => $office->id]);

    // The same grant twice violates the composite primary key.
    expect(fn () => DB::table('hr_admin_offices')->insert(['user_id' => $user->id, 'office_id' => $office->id]))
        ->toThrow(QueryException::class);
});
