<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Office;
use App\Models\Relationship;
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

    $this->spouse = Relationship::query()->create(['code' => 'spouse', 'description' => 'Spouse']);
    $this->child = Relationship::query()->create(['code' => 'child', 'description' => 'Child']);
});

it('creates the listed dependents', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Maria Perez', 'relationship_id' => $this->spouse->id, 'birth_date' => '2003-05-11'],
                ['name' => 'Juan Perez', 'relationship_id' => $this->child->id, 'birth_date' => '2024-02-02'],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(2);
});

it('replaces the whole list — removed dependents are gone', function (): void {
    EmployeeDependent::factory()->count(3)->create([
        'employee_id' => $this->employee->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Maria Perez', 'relationship_id' => $this->spouse->id],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(1)
        ->and(EmployeeDependent::query()->first()->name)->toBe('Maria Perez');
});

it('clears every dependent when given an empty list', function (): void {
    EmployeeDependent::factory()->count(2)->create([
        'employee_id' => $this->employee->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(0);
});

// The one that catches a naive `EmployeeDependent::truncate()` or a missing WHERE.
it('never touches another employee dependents', function (): void {
    $other = Employee::factory()->create(['current_office_id' => $this->office->id]);
    EmployeeDependent::factory()->count(2)->create([
        'employee_id' => $other->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertOk();

    expect(EmployeeDependent::query()->where('employee_id', $other->id)->count())->toBe(2);
});

it('rejects an unknown relationship id', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Ghost', 'relationship_id' => '0199a000-0000-7000-8000-000000000000'],
            ],
        ])
        ->assertStatus(400)
        ->assertJsonStructure(['error']);
});

it('404s for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertNotFound();
});

it('logs each deletion to the activity log for audit trail', function (): void {
    EmployeeDependent::factory()->count(2)->create([
        'employee_id' => $this->employee->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertOk();

    $deletions = Activity::query()
        ->where('log_name', 'employee_profile')
        ->where('subject_type', EmployeeDependent::class)
        ->where('event', 'deleted')
        ->get();

    expect($deletions->count())->toBe(2)
        ->and($deletions->every(fn (Activity $a): bool => $a->causer_id === $this->hr->id))->toBeTrue();
});

it('rejects more than 20 dependents', function (): void {
    $dependents = collect(range(1, 21))->map(fn (int $i): array => [
        'name' => "Dependent $i",
        'relationship_id' => $this->child->id,
    ])->toArray();

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => $dependents])
        ->assertStatus(400)
        ->assertJsonStructure(['error']);
});
