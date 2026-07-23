<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the session envelope for a plain employee', function (): void {
    $office = Office::factory()->create();
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.is_system_admin', false)
        ->assertJsonPath('data.has_reports', false)
        ->assertJsonPath('data.hr_offices', [])
        ->assertJsonPath('data.employee.current_office_id', $office->id);
});

it('reports has_reports for a manager and hr_offices for an HR admin', function (): void {
    $office = Office::factory()->create();
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);
    Employee::factory()->create(['current_reports_to_id' => $manager->id]);
    $managerUser->hrAdminOffices()->attach($office->id);

    Sanctum::actingAs($managerUser);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.has_reports', true)
        ->assertJsonPath('data.hr_offices', [$office->id]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
