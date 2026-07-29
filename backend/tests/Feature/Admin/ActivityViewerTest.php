<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M8c Task 1: a read-only, filterable, paginated window over the Spatie activity log
| every LogsActivity model (Office/Department/Employee, from M8a/M8b) already writes to.
| Same is_system_admin gating as the rest of the admin group: the log spans every
| subject type company-wide, nothing to scope-check against a single office, so a
| non-admin gets the default 403 (not 404-not-403).
|
| Model factories can auto-log a bare `created` event with no causer (no authenticated
| actor at factory time), so filter assertions below target rows deterministically by
| log_name/event rather than assuming an exact total row count.
*/

it('lists activity newest-first, filters by log_name/event/causer_id/date window, and exposes pagination meta', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    Sanctum::actingAs($admin);

    $org = Organization::factory()->create();

    // created (office, event=created) — timestamps a beat apart so ->latest() is provable.
    $officeA = Office::factory()->create(['organization_id' => $org->id, 'name' => 'Alpha Branch']);
    $officeA->update(['name' => 'Alpha Branch Renamed']); // office, event=updated, causer=admin

    $employee = Employee::factory()->create(['first_name' => 'Juan']);
    $employee->update(['first_name' => 'Juana']); // employee, event=updated, causer=admin

    $response = $this->getJson('/api/v1/admin/activity')->assertOk();

    $rows = $response->json('data');
    expect($rows)->not->toBeEmpty();

    // Newest-first: the very first row is the most recently-created activity row.
    $createdAts = array_column($rows, 'created_at');
    $sorted = $createdAts;
    rsort($sorted);
    expect($createdAts)->toBe($sorted);

    // Pagination meta is present.
    expect($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.per_page'))->toBe(50)
        ->and($response->json('meta.total'))->toBeInt()
        ->and($response->json('meta.last_page'))->toBeInt();

    // ?log_name=office returns only office rows.
    $officeOnly = $this->getJson('/api/v1/admin/activity?log_name=office')->assertOk();
    $officeRows = $officeOnly->json('data');
    expect($officeRows)->not->toBeEmpty();
    foreach ($officeRows as $row) {
        expect($row['log_name'])->toBe('office')
            ->and($row['subject_type'])->toBe(Office::class);
    }

    // ?event=updated filters to updates only, across both office and employee rows.
    $updatedOnly = $this->getJson('/api/v1/admin/activity?event=updated')->assertOk();
    $updatedRows = $updatedOnly->json('data');
    expect($updatedRows)->not->toBeEmpty();
    foreach ($updatedRows as $row) {
        expect($row['event'])->toBe('updated');
    }
    expect(collect($updatedRows)->pluck('subject_id'))
        ->toContain($officeA->id, $employee->id);

    // ?causer_id=<admin> filters by actor — both updates above were made while the admin
    // was the acting user, so this narrows to exactly those (never the causer-less
    // factory-create rows).
    $byCauser = $this->getJson("/api/v1/admin/activity?causer_id={$admin->id}")->assertOk();
    $causerRows = $byCauser->json('data');
    expect($causerRows)->not->toBeEmpty();
    foreach ($causerRows as $row) {
        expect($row['causer_id'])->toBe($admin->id);
    }

    // A from/to date window spanning today includes today's rows; a window entirely in
    // the past excludes them.
    $today = now()->toDateString();
    $windowed = $this->getJson("/api/v1/admin/activity?from={$today}&to={$today}")->assertOk();
    expect($windowed->json('data'))->not->toBeEmpty();

    $yesterday = now()->subDay()->toDateString();
    $twoDaysAgo = now()->subDays(2)->toDateString();
    $pastWindow = $this->getJson("/api/v1/admin/activity?from={$twoDaysAgo}&to={$yesterday}")->assertOk();
    expect($pastWindow->json('data'))->toBe([]);
});

it('forbids a non-system-admin from the activity viewer (403, not 404 — nothing to enumerate)', function (): void {
    Office::factory()->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));

    $this->getJson('/api/v1/admin/activity')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});
