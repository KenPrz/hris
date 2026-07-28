<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M8a Task 4: sysadmin-gated department create/update/archive/unarchive/list — the org
| tree's third tier, mirroring OfficeCrudTest. Same admin-gating shape: is_system_admin
| authorizes, a plain user gets the default 403 (not 404-not-403 — this is a
| system-admin surface, nothing to enumerate). Archive-never-delete: no DELETE route;
| archived_at toggles via the two dedicated endpoints, reusing the generic
| AlreadyArchived/NotArchived exceptions verbatim (subjectType 'department').
|
| The one real difference from Office: `departments.code` is unique per
| (office_id, code), not globally, so a duplicate code in a DIFFERENT office must
| succeed — asserted explicitly below.
*/

function createDepartmentPayload(string $officeId, array $overrides = []): array
{
    return array_merge([
        'office_id' => $officeId,
        'name' => 'Engineering',
        'code' => 'ENG-01',
    ], $overrides);
}

it('lets a system admin create a department, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $office = Office::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/departments', createDepartmentPayload($office->id))
        ->assertCreated();

    $departmentId = $response->json('data.id');

    expect($response->json('data.office_id'))->toBe($office->id)
        ->and($response->json('data.name'))->toBe('Engineering')
        ->and($response->json('data.code'))->toBe('ENG-01')
        ->and($response->json('data.archived_at'))->toBeNull();

    $this->assertDatabaseHas('departments', [
        'id' => $departmentId,
        'office_id' => $office->id,
        'code' => 'ENG-01',
    ]);

    $activity = Activity::query()->where('subject_id', $departmentId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Department::class)
        ->and($activity->description)->toBe('created');
});

it('rejects a duplicate department code within the same office with a clean 422', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $office = Office::factory()->create();
    Department::factory()->create(['office_id' => $office->id, 'code' => 'DUP-01']);

    $this->postJson('/api/v1/admin/departments', createDepartmentPayload($office->id, ['code' => 'DUP-01']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'duplicate_department_code');
});

it('allows the same department code in a different office (per-office scope, not global)', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();
    Department::factory()->create(['office_id' => $officeA->id, 'code' => 'SHARED-01']);

    $this->postJson('/api/v1/admin/departments', createDepartmentPayload($officeB->id, ['code' => 'SHARED-01']))
        ->assertCreated()
        ->assertJsonPath('data.office_id', $officeB->id)
        ->assertJsonPath('data.code', 'SHARED-01');

    $this->assertDatabaseHas('departments', ['office_id' => $officeB->id, 'code' => 'SHARED-01']);
});

it('lets a system admin update a department, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id, 'name' => 'Old Name', 'code' => 'OLD-01']);
    Sanctum::actingAs($admin);

    $response = $this->patchJson("/api/v1/admin/departments/{$department->id}", createDepartmentPayload($office->id, [
        'name' => 'New Name',
        'code' => 'NEW-01',
    ]))->assertOk();

    expect($response->json('data.name'))->toBe('New Name')
        ->and($response->json('data.code'))->toBe('NEW-01');

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'name' => 'New Name',
        'code' => 'NEW-01',
    ]);

    $activity = Activity::query()
        ->where('subject_id', $department->id)
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Department::class);
});

it('rejects an update whose code collides with another department in the same office', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $office = Office::factory()->create();
    Department::factory()->create(['office_id' => $office->id, 'code' => 'TAKEN-01']);
    $department = Department::factory()->create(['office_id' => $office->id, 'code' => 'MINE-01']);

    $this->patchJson("/api/v1/admin/departments/{$department->id}", createDepartmentPayload($office->id, ['code' => 'TAKEN-01']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'duplicate_department_code');
});

it('allows a department update that keeps its own code unchanged', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id, 'code' => 'SAME-01', 'name' => 'Old']);

    $this->patchJson("/api/v1/admin/departments/{$department->id}", createDepartmentPayload($office->id, [
        'code' => 'SAME-01',
        'name' => 'Renamed',
    ]))->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.code', 'SAME-01');
});

it('archives a department, logs it, and refuses to re-archive', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $department = Department::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/admin/departments/{$department->id}/archive")->assertOk();

    expect($response->json('data.archived_at'))->not->toBeNull();

    $department->refresh();
    expect($department->archived_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('subject_id', $department->id)
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id);

    $this->postJson("/api/v1/admin/departments/{$department->id}/archive")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_archived');
});

it('unarchives a department and refuses to unarchive an active one', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $department = Department::factory()->create(['archived_at' => now()]);

    $response = $this->postJson("/api/v1/admin/departments/{$department->id}/unarchive")->assertOk();

    expect($response->json('data.archived_at'))->toBeNull();

    $department->refresh();
    expect($department->archived_at)->toBeNull();

    $this->postJson("/api/v1/admin/departments/{$department->id}/unarchive")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_archived');
});

it('lists departments excluding archived by default, including with include_archived, filtering by office', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();

    $active = Department::factory()->create(['office_id' => $officeA->id, 'name' => 'Alpha Dept']);
    $archived = Department::factory()->create(['office_id' => $officeA->id, 'name' => 'Zeta Dept', 'archived_at' => now()]);
    $otherOfficeDept = Department::factory()->create(['office_id' => $officeB->id, 'name' => 'Beta Dept']);

    $default = $this->getJson('/api/v1/admin/departments')->assertOk();
    expect($default->json('data.*.name'))->toBe(['Alpha Dept', 'Beta Dept']);

    $withArchived = $this->getJson('/api/v1/admin/departments?include_archived=1')->assertOk();
    expect($withArchived->json('data.*.name'))->toBe(['Alpha Dept', 'Beta Dept', 'Zeta Dept']);

    $filtered = $this->getJson("/api/v1/admin/departments?office={$officeA->id}&include_archived=1")->assertOk();
    expect($filtered->json('data.*.id'))->toContain($active->id, $archived->id)
        ->and($filtered->json('data.*.id'))->not->toContain($otherOfficeDept->id)
        ->and($filtered->json('data.*.name'))->toBe(['Alpha Dept', 'Zeta Dept']);
});

it('forbids a non-system-admin on every department endpoint (403, not 404 — nothing to enumerate)', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));

    $this->getJson('/api/v1/admin/departments')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson('/api/v1/admin/departments', createDepartmentPayload($office->id, ['code' => 'SHOULD-NOT']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->patchJson("/api/v1/admin/departments/{$department->id}", createDepartmentPayload($office->id, ['name' => 'Should Not Change']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson("/api/v1/admin/departments/{$department->id}/archive")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson("/api/v1/admin/departments/{$department->id}/unarchive")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->assertDatabaseMissing('departments', ['code' => 'SHOULD-NOT']);
    $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => $department->name, 'archived_at' => null]);
});
