<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->office = Office::factory()->create(['code' => 'CEB']);
    $this->employee = Employee::factory()->create(['current_office_id' => $this->office->id]);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr->hrAdminOffices()->attach($this->office->id);
    $this->hr = $this->hr->fresh();

    $this->payload = [
        'salutation' => 'Mr.',
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
        'mobile' => '09166229187',
        'gender' => 'male',
        'birth_date' => '2002-01-23',
        'marital_status' => 'single',
        'citizenship' => 'Filipino',
        'religion' => 'Roman Catholic',
        'blood_type' => 'O+',
    ];
});

it('creates the profile row on first write', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk()
        ->assertJsonPath('data.details.nickname', 'KENPE')
        ->assertJsonPath('data.personal.blood_type', 'O+');

    expect(EmployeeProfile::query()->count())->toBe(1);
});

it('updates in place on a second write rather than creating a second row', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk();

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'nickname' => 'KEN'])
        ->assertOk()
        ->assertJsonPath('data.details.nickname', 'KEN');

    expect(EmployeeProfile::query()->count())->toBe(1);
});

it('rejects a value outside a closed set', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'blood_type' => 'Z+'])
        ->assertStatus(400)
        ->assertJsonStructure(['error']);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'gender' => 'Male'])   // capitalised: not the backed value
        ->assertStatus(400);
});

it('accepts a fully empty payload — every profile field is optional', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", [])
        ->assertOk()
        ->assertJsonPath('data.details.nickname', null);
});

it('refuses an employee their own profile edit', function (): void {
    $self = User::factory()->create();
    $this->employee->update(['user_id' => $self->id]);

    $this->actingAs($self->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertNotFound();

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('404s for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertNotFound();
});

it('writes an activity log entry under the employee_profile log name', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk();

    expect(Activity::query()->where('log_name', 'employee_profile')->exists())->toBeTrue();
});
