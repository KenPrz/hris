<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeWorld(): array
{
    $manila = Office::factory()->create(['code' => 'MNL']);
    $cebu = Office::factory()->create(['code' => 'CEB']);

    $adminUser = User::factory()->create(['is_system_admin' => true]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $manila->id]);

    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $manila->id,
        'current_reports_to_id' => $manager->id,
    ]);

    $hrUser = User::factory()->create();
    $hr = Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);

    return compact('manila', 'cebu', 'adminUser', 'managerUser', 'manager', 'reportUser', 'report', 'hrUser', 'cebuWorker');
}

it('404s when an employee views a peer', function (): void {
    ['reportUser' => $reportUser, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($reportUser);

    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a manager see a direct report but 404s on a peer', function (): void {
    ['managerUser' => $managerUser, 'report' => $report, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($managerUser);

    $this->getJson("/api/v1/employees/{$report->id}")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a Manila HR admin see a Manila worker but 404s on Cebu', function (): void {
    ['hrUser' => $hrUser, 'report' => $manilaWorker, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($hrUser);

    $this->getJson("/api/v1/employees/{$manilaWorker->id}")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a system admin see everyone', function (): void {
    ['adminUser' => $adminUser, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($adminUser);

    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertOk();
});

it('scopes the index list to what the actor may see', function (): void {
    $world = makeWorld();
    Sanctum::actingAs($world['managerUser']);

    $ids = $this->getJson('/api/v1/employees')->assertOk()->json('data.*.id');

    // The manager sees themselves and their report, not the Cebu worker.
    expect($ids)->toContain($world['manager']->id)
        ->and($ids)->toContain($world['report']->id)
        ->and($ids)->not->toContain($world['cebuWorker']->id);
});
