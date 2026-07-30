<?php

declare(strict_types=1);

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('stores a profile keyed on employee_id and casts its enums', function (): void {
    $employee = Employee::factory()->create();

    EmployeeProfile::query()->create([
        'employee_id' => $employee->id,
        'salutation' => 'Mr.',
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
        'mobile' => '09166229187',
        'gender' => Gender::Male,
        'birth_date' => '2002-01-23',
        'marital_status' => MaritalStatus::Single,
        'citizenship' => 'Filipino',
        'religion' => 'Roman Catholic',
        'blood_type' => BloodType::OPositive,
    ]);

    $profile = $employee->fresh()->profile;

    expect($profile)->not->toBeNull()
        ->and($profile->getKey())->toBe($employee->id)
        ->and($profile->gender)->toBe(Gender::Male)
        ->and($profile->marital_status)->toBe(MaritalStatus::Single)
        ->and($profile->blood_type)->toBe(BloodType::OPositive)
        ->and($profile->birth_date->toDateString())->toBe('2002-01-23');
});

it('derives age from birth_date in the employee office timezone, not UTC', function (): void {
    // 2002-01-23 born. Freeze the clock at 2026-01-22 16:30 UTC — which is already
    // 2026-01-23 00:30 in Asia/Manila, i.e. the birthday HAS passed locally but has NOT
    // passed in UTC. A naive now() yields 23; the office timezone yields 24.
    Carbon::setTestNow(Carbon::parse('2026-01-22 16:30:00', 'UTC'));

    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $profile = EmployeeProfile::query()->create([
        'employee_id' => $employee->id,
        'birth_date' => '2002-01-23',
    ]);

    expect($profile->fresh()->age)->toBe(24);

    Carbon::setTestNow();
});

it('returns a null age when birth_date is unset', function (): void {
    $employee = Employee::factory()->create();
    $profile = EmployeeProfile::query()->create(['employee_id' => $employee->id]);

    expect($profile->age)->toBeNull();
});

it('cascades the profile away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeProfile::query()->create(['employee_id' => $employee->id, 'nickname' => 'KENPE']);

    $employee->delete();

    expect(EmployeeProfile::query()->count())->toBe(0);
});
