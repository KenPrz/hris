<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\EmployeeProfile;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->office = Office::factory()->create(['code' => 'CEB', 'region' => 'VII']);

    $this->selfUser = User::factory()->create();
    $this->employee = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'employee_no' => '2506366',
        'first_name' => 'Ken Daryl',
        'middle_name' => 'Austero',
        'last_name' => 'Perez',
        'current_office_id' => $this->office->id,
    ]);

    EmployeeProfile::factory()->create([
        'employee_id' => $this->employee->id,
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City',
        'mobile' => '09166229187',
        'birth_date' => '2002-01-23',
        'religion' => 'Roman Catholic',
    ]);

    $tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
    EmployeeIdentification::query()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $tin->id,
        'number' => '653536955000',
    ]);
});

it('returns the full profile to the employee themself', function (): void {
    $this->actingAs($this->selfUser)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.employee_no', '2506366')
        ->assertJsonPath('data.details.nickname', 'KENPE')
        ->assertJsonPath('data.contact.mobile', '09166229187')
        ->assertJsonPath('data.personal.birth_date', '2002-01-23')
        ->assertJsonPath('data.personal.religion', 'Roman Catholic')
        ->assertJsonPath('data.assignment.region', 'VII')
        ->assertJsonPath('data.identifications.0.category_code', 'TIN')
        ->assertJsonPath('data.identifications.0.number', '653536955000')
        ->assertJsonPath('data.identifications.0.has_scan', false);
});

it('404s /me/profile for a user with no employee record', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/me/profile')
        ->assertNotFound();
});

it('returns an empty-but-valid profile when no profile row exists yet', function (): void {
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id, 'current_office_id' => $this->office->id]);

    // assertJsonPath('data.details.nickname', null) alone would also pass if `details`
    // were missing entirely — Arr::get() returns null for an absent path just as readily
    // as for a present-but-null one. assertJsonStructure below proves the keys actually
    // exist before the assertJsonPath calls claim they're null-but-present.
    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'employee_id', 'employee_no', 'full_name',
            'details' => ['salutation', 'first_name', 'middle_name', 'last_name', 'name_suffix', 'nickname'],
            'contact' => ['home_address', 'personal_email', 'phone', 'fax', 'mobile', 'emergency_contact'],
            'personal' => ['gender', 'birth_date', 'age', 'birthplace', 'marital_status', 'citizenship', 'religion', 'blood_type'],
            'assignment' => ['designation', 'business_unit', 'reports_to', 'employment_status', 'location', 'region', 'labor_type', 'hired_at', 'work_shift'],
            'dependents',
            'identifications',
        ]])
        ->assertJsonPath('data.details.nickname', null)
        ->assertJsonPath('data.personal.age', null)
        ->assertJsonPath('data.dependents', [])
        ->assertJsonPath('data.identifications', []);
});

it('returns the full profile to an in-scope HR Admin', function (): void {
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($this->office->id);

    $this->actingAs($hr->fresh())
        ->getJson("/api/v1/admin/employees/{$this->employee->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '653536955000');
});

it('404s the admin profile read for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->getJson("/api/v1/admin/employees/{$this->employee->id}/profile")
        ->assertNotFound();
});

// The redaction contract. This is the test that must never be relaxed.
it('gives a manager contact and assignment but no address, personal block, or IDs', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->office->id,
    ]);
    $this->employee->update(['current_reports_to_id' => $manager->id]);

    $response = $this->actingAs($managerUser->fresh())
        ->getJson("/api/v1/employees/{$this->employee->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.contact.mobile', '09166229187')
        ->assertJsonPath('data.assignment.region', 'VII');

    $body = $response->json('data');

    expect($body)->not->toHaveKey('personal')
        ->and($body)->not->toHaveKey('dependents')
        ->and($body)->not->toHaveKey('identifications')
        ->and($body['contact'])->not->toHaveKey('home_address');

    // Exact key sets, not just the handful of forbidden names above. A mutation that adds
    // an unexpected key anywhere in the payload (e.g. a field copy-pasted from the full
    // resource's `personal` block straight onto the top level) would pass every
    // not->toHaveKey() check above yet still leak — this is what actually pins the
    // "separate class, not a filtered one" invariant, which is otherwise only a docblock
    // claim. Without this, a refactor that merges the two resources behind a boolean flag
    // would pass this whole test as long as it remembered the four names above.
    expect(array_keys($body))->toBe(['employee_id', 'employee_no', 'full_name', 'contact', 'assignment'])
        ->and(array_keys($body['contact']))->toBe(['personal_email', 'phone', 'mobile']);

    // Belt and braces: the raw payload must not contain the TIN anywhere at all.
    expect($response->getContent())->not->toContain('653536955000')
        ->and($response->getContent())->not->toContain('Tagles Compound');
});

it('404s the redacted read for an unrelated employee', function (): void {
    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($stranger->fresh())
        ->getJson("/api/v1/employees/{$this->employee->id}/profile")
        ->assertNotFound();
});
