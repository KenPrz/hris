<?php

declare(strict_types=1);

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(Database\Seeders\DatabaseSeeder::class);
});

it('seeds a profile for every employee', function (): void {
    expect(EmployeeProfile::query()->count())->toBe(Employee::query()->count());
});

it('seeds every gender, marital_status, and blood_type as a valid backed enum case', function (): void {
    // Read the raw text columns directly, bypassing the model's enum cast, so a typo'd
    // seed value is caught by an assertion here rather than only surfacing later as a
    // ValueError the first time something reads the model attribute (or a 400 in the UI).
    $rows = DB::table('employee_profiles')->select(['gender', 'marital_status', 'blood_type'])->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(Gender::tryFrom($row->gender))->not->toBeNull()
            ->and(MaritalStatus::tryFrom($row->marital_status))->not->toBeNull()
            ->and(BloodType::tryFrom($row->blood_type))->not->toBeNull();
    }
});

it('seeds dependents for some employees but not all, including two same-relationship children and a spouse', function (): void {
    $totalEmployees = Employee::query()->count();
    $employeesWithDependents = EmployeeDependent::query()->distinct()->count('employee_id');

    expect($employeesWithDependents)->toBeGreaterThan(0)
        ->and($employeesWithDependents)->toBeLessThan($totalEmployees);

    // At least one employee has two dependents sharing the same relationship (two
    // children) — the path a naive upsert-by-relationship write would collapse into one.
    $duplicateRelationshipRows = DB::table('employee_dependents')
        ->select('employee_id', 'relationship_id')
        ->groupBy('employee_id', 'relationship_id')
        ->havingRaw('count(*) >= 2')
        ->get();
    expect($duplicateRelationshipRows)->not->toBeEmpty();

    // At least one dependent is a spouse.
    $hasSpouse = EmployeeDependent::query()
        ->whereHas('relationship', fn ($q) => $q->where('code', 'spouse'))
        ->exists();
    expect($hasSpouse)->toBeTrue();

    // At least one employee has zero dependents.
    $employeeWithoutDependents = Employee::query()->doesntHave('dependents')->exists();
    expect($employeeWithoutDependents)->toBeTrue();
});

it('seeds identifications for most, but not necessarily all, employees', function (): void {
    $totalEmployees = Employee::query()->count();
    $employeesWithIdentifications = EmployeeIdentification::query()->distinct()->count('employee_id');

    expect($employeesWithIdentifications)->toBeGreaterThanOrEqual((int) ceil($totalEmployees * 0.5))
        ->and($employeesWithIdentifications)->toBeLessThanOrEqual($totalEmployees);
});

it('never seeds an identification with a media scan (no RustFS dependency)', function (): void {
    EmployeeIdentification::query()->get()->each(
        fn (EmployeeIdentification $identification) => expect($identification->hasMedia('scan'))->toBeFalse(),
    );
});
