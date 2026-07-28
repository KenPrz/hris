<?php

declare(strict_types=1);

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('has first_name, middle_name, last_name, name_suffix on employees', function (): void {
    expect(Schema::hasColumn('employees', 'first_name'))->toBeTrue();
    expect(Schema::hasColumn('employees', 'middle_name'))->toBeTrue();
    expect(Schema::hasColumn('employees', 'last_name'))->toBeTrue();
    expect(Schema::hasColumn('employees', 'name_suffix'))->toBeTrue();
});

it('composes full_name from first, middle, last, and suffix', function (): void {
    $employee = Employee::factory()->create([
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'last_name' => 'Cruz',
        'name_suffix' => 'Jr.',
    ]);

    expect($employee->full_name)->toBe('Juan Santos Cruz Jr.');
});

it('composes full_name without double spaces when middle and suffix are null', function (): void {
    $employee = Employee::factory()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Reyes',
        'name_suffix' => null,
    ]);

    expect($employee->full_name)->toBe('Maria Reyes');
});

it('writes an activity log entry when an employee name changes', function (): void {
    $employee = Employee::factory()->create(['first_name' => 'Juan']);
    $employee->update(['first_name' => 'Juanito']);

    expect(Activity::query()->where('log_name', 'employee')->where('subject_id', $employee->id)->exists())->toBeTrue();
});
