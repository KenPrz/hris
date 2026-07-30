<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a dependent to an employee and a relationship', function (): void {
    $employee = Employee::factory()->create();
    $spouse = Relationship::query()->create(['code' => 'spouse', 'description' => 'Spouse']);

    EmployeeDependent::query()->create([
        'employee_id' => $employee->id,
        'name' => 'Maria Perez',
        'relationship_id' => $spouse->id,
        'birth_date' => '2003-05-11',
    ]);

    $dependent = $employee->fresh()->dependents->first();

    expect($dependent)->not->toBeNull()
        ->and($dependent->name)->toBe('Maria Perez')
        ->and($dependent->relationship->code)->toBe('spouse')
        ->and($dependent->birth_date->toDateString())->toBe('2003-05-11');
});

it('rejects a duplicate relationship code', function (): void {
    Relationship::query()->create(['code' => 'child', 'description' => 'Child']);

    expect(fn () => Relationship::query()->create(['code' => 'child', 'description' => 'Child again']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

// ponytail: employee_id is nullable by explicit user decision (spec, decision 8). This
// test PINS that decision so a later "tighten the schema" pass has to argue with a
// failing test rather than silently changing the contract.
it('allows a dependent with no employee, deliberately', function (): void {
    $parent = Relationship::query()->create(['code' => 'parent', 'description' => 'Parent']);

    $orphan = EmployeeDependent::query()->create([
        'employee_id' => null,
        'name' => 'Unassigned Person',
        'relationship_id' => $parent->id,
    ]);

    expect($orphan->employee_id)->toBeNull()
        ->and(EmployeeDependent::query()->count())->toBe(1);
});

it('cascades dependents away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeDependent::factory()->create(['employee_id' => $employee->id]);

    $employee->delete();

    expect(EmployeeDependent::query()->count())->toBe(0);
});
