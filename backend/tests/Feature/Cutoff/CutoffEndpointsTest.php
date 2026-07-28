<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffCalendar;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M7a Task 8: the HTTP surface for cutoffs — list/close/reopen. Mirrors
| LeaveTypeConfigTest/HolidayReadWriteTest's established patterns — office-scoped access
| that 404s uniformly (never a 403 that would confirm an office exists to a caller who
| doesn't administer it), and a FormRequest validation failure landing as 400
| validation_failed (not Laravel's default 422 — docs/03-api.md reserves 422 for
| domain-rejected requests).
*/

function cutoffOffice(): Office
{
    return Office::factory()->create();
}

function cutoffHrAdminOf(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

// --- List -------------------------------------------------------------

it('lists the office\'s stored cutoff periods plus the current open window', function (): void {
    $manila = cutoffOffice();
    $cebu = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    $closed = CutoffPeriod::factory()->closed()->create([
        'office_id' => $manila->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-15',
        'closed_by' => $hrUser->id,
    ]);
    // Wrong office — excluded.
    CutoffPeriod::factory()->create(['office_id' => $cebu->id]);

    Sanctum::actingAs($hrUser);

    $response = $this->getJson("/api/v1/office/cutoffs?office={$manila->id}")
        ->assertOk();

    $ids = $response->json('data.*.id');
    expect($ids)->toContain($closed->id);

    $window = CutoffCalendar::windowFor(now()->toDateString());
    $current = collect($response->json('data'))->firstWhere('start_date', $window['start']);

    expect($current)->not->toBeNull()
        ->and($current['end_date'])->toBe($window['end'])
        ->and($current['office_id'])->toBe($manila->id)
        ->and($current['state'])->toBe('open');
});

it('404s listing a foreign office\'s cutoffs, identically to a fabricated office', function (): void {
    $manila = cutoffOffice();
    $cebu = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    CutoffPeriod::factory()->create(['office_id' => $cebu->id]);

    Sanctum::actingAs($hrUser);

    $outOfScope = $this->getJson("/api/v1/office/cutoffs?office={$cebu->id}")
        ->assertStatus(404);

    $fabricated = $this->getJson('/api/v1/office/cutoffs?office='.(string) Str::uuid7())
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');
});

// --- Close --------------------------------------------------------------

it('lets an HR admin close a clean period for an office they administer', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);

    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $manila->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/cutoffs/close', [
        'office_id' => $manila->id,
        'period_start' => '2026-07-01',
    ])->assertOk();

    expect($response->json('data.office_id'))->toBe($manila->id)
        ->and($response->json('data.start_date'))->toBe('2026-07-01')
        ->and($response->json('data.end_date'))->toBe('2026-07-15')
        ->and($response->json('data.state'))->toBe('closed')
        ->and($response->json('data.closed_by'))->toBe($hrUser->id)
        ->and($response->json('data.closed_at'))->not->toBeNull();

    $this->assertDatabaseHas('cutoff_periods', [
        'office_id' => $manila->id,
        'start_date' => '2026-07-01',
        'state' => 'closed',
    ]);
});

it('404s closing a cutoff for an office the admin does not administer, identically to a fabricated office', function (): void {
    $manila = cutoffOffice();
    $cebu = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $payload = fn (string $officeId): array => ['office_id' => $officeId, 'period_start' => '2026-07-01'];

    $outOfScope = $this->postJson('/api/v1/office/cutoffs/close', $payload($cebu->id))
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/office/cutoffs/close', $payload((string) Str::uuid7()))
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('cutoff_periods', 0);
});

it('400s a missing period_start', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/cutoffs/close', ['office_id' => $manila->id])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('422s closing on a non-boundary period_start', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/cutoffs/close', [
        'office_id' => $manila->id,
        'period_start' => '2026-07-02',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_cutoff_start');
});

it('422s closing a period with an unresolved exception, carrying blocking details', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);

    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $manila->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => true,
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/cutoffs/close', [
        'office_id' => $manila->id,
        'period_start' => '2026-07-01',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cutoff_has_unresolved_exceptions');

    expect($response->json('error.details.incomplete_dates'))->toBe(['2026-07-10']);
});

it('409s closing an already-closed period', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/cutoffs/close', [
        'office_id' => $manila->id,
        'period_start' => '2026-07-01',
    ])->assertOk();

    $this->postJson('/api/v1/office/cutoffs/close', [
        'office_id' => $manila->id,
        'period_start' => '2026-07-01',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'cutoff_already_closed');
});

// --- Reopen ---------------------------------------------------------------

it('lets an HR admin reopen a closed period for an office they administer', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $manila->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'closed_by' => $hrUser->id,
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson("/api/v1/office/cutoffs/{$period->id}/reopen", [
        'reason' => 'Payroll found a missed correction.',
    ])->assertOk();

    expect($response->json('data.id'))->toBe($period->id)
        ->and($response->json('data.state'))->toBe('open')
        ->and($response->json('data.closed_by'))->toBeNull()
        ->and($response->json('data.closed_at'))->toBeNull();

    $this->assertDatabaseHas('cutoff_periods', [
        'id' => $period->id,
        'state' => 'open',
        'closed_by' => null,
    ]);
});

it('404s reopening a period belonging to an office the admin does not administer, identically to a fabricated period', function (): void {
    $manila = cutoffOffice();
    $cebu = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $outOfScopePeriod = CutoffPeriod::factory()->closed()->create(['office_id' => $cebu->id]);

    Sanctum::actingAs($hrUser);

    $payload = ['reason' => 'Payroll found a missed correction.'];

    $outOfScope = $this->postJson("/api/v1/office/cutoffs/{$outOfScopePeriod->id}/reopen", $payload)
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/office/cutoffs/'.(string) Str::uuid7().'/reopen', $payload)
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');
});

it('400s a reopen without a reason', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $period = CutoffPeriod::factory()->closed()->create(['office_id' => $manila->id]);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/office/cutoffs/{$period->id}/reopen", [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('409s reopening a period that is not closed', function (): void {
    $manila = cutoffOffice();
    $hrUser = cutoffHrAdminOf($manila);
    $period = CutoffPeriod::factory()->create(['office_id' => $manila->id, 'state' => 'open']);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/office/cutoffs/{$period->id}/reopen", [
        'reason' => 'Payroll found a missed correction.',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'cutoff_not_closed');
});
