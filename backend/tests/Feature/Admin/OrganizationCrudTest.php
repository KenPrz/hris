<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M8a Task 2: sysadmin-gated organization create/update/list — the root of the org
| tree. Mirrors PayRuleReadWriteTest's admin-gating shape: is_system_admin authorizes,
| a plain user gets the default 403 (not the 404-not-403 enumeration discipline used
| for per-office/per-subject resources), and a create/update is picked up by
| Organization's LogsActivity automatically.
*/

it('lets a system admin create an organization, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Delsan Transport Corp',
        'legal_name' => 'Delsan Transport Corporation',
        'tin' => '123-456-789-000',
        'timezone' => 'Asia/Manila',
    ])->assertCreated();

    $orgId = $response->json('data.id');

    expect($response->json('data.name'))->toBe('Delsan Transport Corp')
        ->and($response->json('data.legal_name'))->toBe('Delsan Transport Corporation')
        ->and($response->json('data.tin'))->toBe('123-456-789-000')
        ->and($response->json('data.timezone'))->toBe('Asia/Manila');

    $this->assertDatabaseHas('organizations', [
        'id' => $orgId,
        'name' => 'Delsan Transport Corp',
    ]);

    $activity = Activity::query()->where('subject_id', $orgId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Organization::class)
        ->and($activity->description)->toBe('created');
});

it('lets a system admin update an organization, and logs it', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    $org = Organization::factory()->create(['name' => 'Old Name']);
    Sanctum::actingAs($admin);

    $response = $this->patchJson("/api/v1/admin/organizations/{$org->id}", [
        'name' => 'New Name',
        'legal_name' => 'New Name Inc.',
        'tin' => null,
        'timezone' => 'Asia/Manila',
    ])->assertOk();

    expect($response->json('data.name'))->toBe('New Name')
        ->and($response->json('data.legal_name'))->toBe('New Name Inc.');

    $this->assertDatabaseHas('organizations', [
        'id' => $org->id,
        'name' => 'New Name',
    ]);

    $activity = Activity::query()
        ->where('subject_id', $org->id)
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_type)->toBe(Organization::class);
});

it('lists organizations ordered by name for a system admin', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    Organization::factory()->create(['name' => 'Zeta Corp']);
    Organization::factory()->create(['name' => 'Alpha Corp']);

    $response = $this->getJson('/api/v1/admin/organizations')->assertOk();

    expect($response->json('data.*.name'))->toBe(['Alpha Corp', 'Zeta Corp']);
});

it('forbids a non-system-admin on create/update/list (403, not 404 — nothing to enumerate)', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));

    $this->getJson('/api/v1/admin/organizations')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Should Not Exist',
        'legal_name' => null,
        'tin' => null,
        'timezone' => 'Asia/Manila',
    ])->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->patchJson("/api/v1/admin/organizations/{$org->id}", [
        'name' => 'Should Not Change',
        'legal_name' => null,
        'tin' => null,
        'timezone' => 'Asia/Manila',
    ])->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->assertDatabaseMissing('organizations', ['name' => 'Should Not Exist']);
    $this->assertDatabaseHas('organizations', ['id' => $org->id, 'name' => $org->name]);
});
