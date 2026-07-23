<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    // 17:30+08:00 is the instant 09:30 UTC — the offset must not be dropped on write.
    $stored = DB::table('attendance_logs')->where('id', $log->id)->value('punched_at');
    expect($log->fresh()->punched_at->utc()->toDateTimeString())->toBe('2026-03-01 09:30:00')
        ->and($stored)->toBe('2026-03-01 09:30:00+00');
});

it('403s when a plain employee tries to manually punch', function (): void {
    $office = Office::factory()->create();
    $employeeUser = User::factory()->create();
    Employee::factory()->for($employeeUser)->create(['current_office_id' => $office->id]);
    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($employeeUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'in',
        'punched_at' => '2026-03-01T08:00:00+08:00',
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('422s when an HR admin tries to manually punch their own record', function (): void {
    $office = Office::factory()->create();
    $hrUser = User::factory()->create();
    $hrEmployee = Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $hrEmployee->id,
        'direction' => 'in',
        'punched_at' => '2026-03-01T08:00:00+08:00',
    ])->assertStatus(422)->assertJsonPath('error.code', 'cannot_punch_self');
});

it('lets a system admin manually punch any employee in any office', function (): void {
    $office = Office::factory()->create();
    $adminUser = User::factory()->create(['is_system_admin' => true]);
    Employee::factory()->for($adminUser)->create(['current_office_id' => $office->id]);

    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($adminUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'in',
        'punched_at' => '2026-03-01T08:00:00+08:00',
    ])->assertCreated();
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
