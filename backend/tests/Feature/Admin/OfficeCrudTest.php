<?php

declare(strict_types=1);

use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M8a Task 3: sysadmin-gated office create/update/archive/unarchive/list — the org
| tree's second tier, and the template Task 4 (departments) mirrors. Same admin-gating
| shape as OrganizationCrudTest: is_system_admin authorizes, a plain user gets the
| default 403 (not 404-not-403 — this is a system-admin surface, nothing to enumerate).
| Archive-never-delete: no DELETE route; archived_at toggles via the two dedicated
| endpoints, and the generic AlreadyArchived/NotArchived exceptions are asserted here
| since Task 4 reuses them verbatim.
*/

function createOfficePayload(string $organizationId, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organizationId,
        'name' => 'Main Branch',
        'code' => 'MB-01',
        'timezone' => 'Asia/Manila',
        'geofence_lat' => 14.5995,
        'geofence_lng' => 120.9842,
        'geofence_radius_m' => 100,
        'ip_allowlist' => ['203.0.113.10'],
        'default_shift_template_id' => null,
    ], $overrides);
}

it('lets a system admin create an office, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $org = Organization::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/offices', createOfficePayload($org->id))
        ->assertCreated();

    $officeId = $response->json('data.id');

    expect($response->json('data.organization_id'))->toBe($org->id)
        ->and($response->json('data.name'))->toBe('Main Branch')
        ->and($response->json('data.code'))->toBe('MB-01')
        ->and($response->json('data.timezone'))->toBe('Asia/Manila')
        ->and($response->json('data.geofence_radius_m'))->toBe(100)
        ->and($response->json('data.ip_allowlist'))->toBe(['203.0.113.10'])
        ->and($response->json('data.archived_at'))->toBeNull();

    $this->assertDatabaseHas('offices', [
        'id' => $officeId,
        'organization_id' => $org->id,
        'code' => 'MB-01',
    ]);

    $activity = Activity::query()->where('subject_id', $officeId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Office::class)
        ->and($activity->description)->toBe('created');
});

it('rejects a duplicate office code with a clean 422', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $org = Organization::factory()->create();
    Office::factory()->create(['organization_id' => $org->id, 'code' => 'DUP-01']);

    $otherOrg = Organization::factory()->create();

    $this->postJson('/api/v1/admin/offices', createOfficePayload($otherOrg->id, ['code' => 'DUP-01']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'duplicate_office_code');
});

it('lets a system admin update an office, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $org = Organization::factory()->create();
    $office = Office::factory()->create(['organization_id' => $org->id, 'name' => 'Old Name', 'code' => 'OLD-01']);
    Sanctum::actingAs($admin);

    $response = $this->patchJson("/api/v1/admin/offices/{$office->id}", createOfficePayload($org->id, [
        'name' => 'New Name',
        'code' => 'NEW-01',
    ]))->assertOk();

    expect($response->json('data.name'))->toBe('New Name')
        ->and($response->json('data.code'))->toBe('NEW-01');

    $this->assertDatabaseHas('offices', [
        'id' => $office->id,
        'name' => 'New Name',
        'code' => 'NEW-01',
    ]);

    $activity = Activity::query()
        ->where('subject_id', $office->id)
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Office::class);
});

it('rejects an update whose code collides with another office', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $org = Organization::factory()->create();
    Office::factory()->create(['organization_id' => $org->id, 'code' => 'TAKEN-01']);
    $office = Office::factory()->create(['organization_id' => $org->id, 'code' => 'MINE-01']);

    $this->patchJson("/api/v1/admin/offices/{$office->id}", createOfficePayload($org->id, ['code' => 'TAKEN-01']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'duplicate_office_code');
});

it('allows an office update that keeps its own code unchanged', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $org = Organization::factory()->create();
    $office = Office::factory()->create(['organization_id' => $org->id, 'code' => 'SAME-01', 'name' => 'Old']);

    $this->patchJson("/api/v1/admin/offices/{$office->id}", createOfficePayload($org->id, [
        'code' => 'SAME-01',
        'name' => 'Renamed',
    ]))->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.code', 'SAME-01');
});

it('archives an office, logs it, and refuses to re-archive', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $office = Office::factory()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/admin/offices/{$office->id}/archive")->assertOk();

    expect($response->json('data.archived_at'))->not->toBeNull();

    $office->refresh();
    expect($office->archived_at)->not->toBeNull();

    $activity = Activity::query()
        ->where('subject_id', $office->id)
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id);

    $this->postJson("/api/v1/admin/offices/{$office->id}/archive")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_archived');
});

it('unarchives an office and refuses to unarchive an active one', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $office = Office::factory()->create(['archived_at' => now()]);

    $response = $this->postJson("/api/v1/admin/offices/{$office->id}/unarchive")->assertOk();

    expect($response->json('data.archived_at'))->toBeNull();

    $office->refresh();
    expect($office->archived_at)->toBeNull();

    $this->postJson("/api/v1/admin/offices/{$office->id}/unarchive")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_archived');
});

it('lists offices excluding archived by default, including with include_archived, filtering by organization', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $active = Office::factory()->create(['organization_id' => $orgA->id, 'name' => 'Alpha Branch']);
    $archived = Office::factory()->create(['organization_id' => $orgA->id, 'name' => 'Zeta Branch', 'archived_at' => now()]);
    $otherOrgOffice = Office::factory()->create(['organization_id' => $orgB->id, 'name' => 'Beta Branch']);

    $default = $this->getJson('/api/v1/admin/offices')->assertOk();
    expect($default->json('data.*.name'))->toBe(['Alpha Branch', 'Beta Branch']);

    $withArchived = $this->getJson('/api/v1/admin/offices?include_archived=1')->assertOk();
    expect($withArchived->json('data.*.name'))->toBe(['Alpha Branch', 'Beta Branch', 'Zeta Branch']);

    $filtered = $this->getJson("/api/v1/admin/offices?organization={$orgA->id}&include_archived=1")->assertOk();
    expect($filtered->json('data.*.id'))->toContain($active->id, $archived->id)
        ->and($filtered->json('data.*.id'))->not->toContain($otherOrgOffice->id)
        ->and($filtered->json('data.*.name'))->toBe(['Alpha Branch', 'Zeta Branch']);
});

it('forbids a non-system-admin on every office endpoint (403, not 404 — nothing to enumerate)', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->create(['organization_id' => $org->id]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));

    $this->getJson('/api/v1/admin/offices')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson('/api/v1/admin/offices', createOfficePayload($org->id, ['code' => 'SHOULD-NOT']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->patchJson("/api/v1/admin/offices/{$office->id}", createOfficePayload($org->id, ['name' => 'Should Not Change']))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson("/api/v1/admin/offices/{$office->id}/archive")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson("/api/v1/admin/offices/{$office->id}/unarchive")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->assertDatabaseMissing('offices', ['code' => 'SHOULD-NOT']);
    $this->assertDatabaseHas('offices', ['id' => $office->id, 'name' => $office->name, 'archived_at' => null]);
});
