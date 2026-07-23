<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets an HR admin backfill a manual punch for an employee in their office', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);

    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'out',
        'punched_at' => '2026-03-01T17:30:00+08:00',
    ])->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.direction', 'out');

    $log = AttendanceLog::first();
    expect($log->recorded_by)->toBe($hrUser->id)
        ->and($log->employee_id)->toBe($target->id);
});

it('404s when HR backfills for an employee outside their scope', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $cebuWorker->id,
        'direction' => 'in',
        'punched_at' => '2026-03-01T08:00:00+08:00',
    ])->assertStatus(404);
});

it('requires a supplied punched_at for a manual entry', function (): void {
    $office = Office::factory()->create();
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);
    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'in',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
