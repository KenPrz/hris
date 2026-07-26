<?php

declare(strict_types=1);

use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M6b-a Task 4: HR-configured leave types (list/create/update, no delete). Mirrors
| HolidayReadWriteTest's two established patterns — office-scoped access that 404s
| uniformly (never a 403 that would confirm an office exists to a caller who doesn't
| administer it), and Spatie's activity log for the create/update audit trail.
*/

function leaveTypeOffice(): Office
{
    return Office::factory()->create();
}

function leaveHrAdminOf(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

// --- List -------------------------------------------------------------

it('lists only the given office\'s leave types, name-ordered', function (): void {
    $manila = leaveTypeOffice();
    $cebu = leaveTypeOffice();
    leaveHrAdminOf($manila);
    $hrUser = leaveHrAdminOf($manila);

    $vacation = LeaveType::factory()->for($manila, 'office')->create(['name' => 'Vacation Leave']);
    $sick = LeaveType::factory()->for($manila, 'office')->create(['name' => 'Sick Leave']);
    // Wrong office — excluded.
    LeaveType::factory()->for($cebu, 'office')->create(['name' => 'Aardvark Leave']);

    Sanctum::actingAs($hrUser);

    $response = $this->getJson("/api/v1/office/leave-types?office={$manila->id}")
        ->assertOk();

    expect($response->json('data.*.id'))->toBe([$sick->id, $vacation->id])
        ->and($response->json('data.*.name'))->toBe(['Sick Leave', 'Vacation Leave']);
});

it('404s listing a foreign office\'s leave types, identically to a fabricated office', function (): void {
    $manila = leaveTypeOffice();
    $cebu = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    LeaveType::factory()->for($cebu, 'office')->create();

    Sanctum::actingAs($hrUser);

    $outOfScope = $this->getJson("/api/v1/office/leave-types?office={$cebu->id}")
        ->assertStatus(404);

    $fabricated = $this->getJson('/api/v1/office/leave-types?office='.(string) Str::uuid7())
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');
});

it('400s a malformed (non-uuid) office rather than 500ing on the uuid cast', function (): void {
    $manila = leaveTypeOffice();
    Sanctum::actingAs(leaveHrAdminOf($manila));

    $this->getJson('/api/v1/office/leave-types?office=not-a-uuid')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

// --- Create -------------------------------------------------------------

it('lets an HR admin create a leave type for an office they administer, and logs it', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/leave-types', [
        'office_id' => $manila->id,
        'name' => 'Vacation Leave',
        'code' => 'VL',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => true,
        'max_carryover_minutes' => 4800,
    ])->assertCreated();

    $leaveTypeId = $response->json('data.id');

    expect($response->json('data'))->toBe([
        'id' => $leaveTypeId,
        'office_id' => $manila->id,
        'name' => 'Vacation Leave',
        'code' => 'VL',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => true,
        'max_carryover_minutes' => 4800,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('leave_types', [
        'id' => $leaveTypeId,
        'office_id' => $manila->id,
        'name' => 'Vacation Leave',
        'code' => 'VL',
    ]);

    $activity = Activity::query()->where('subject_id', $leaveTypeId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_id)->toBe($leaveTypeId)
        ->and($activity->subject_type)->toBe(LeaveType::class);
});

it('404s creating a leave type for an office the admin does not administer, identically to a fabricated office', function (): void {
    $manila = leaveTypeOffice();
    $cebu = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $payload = fn (string $officeId): array => [
        'office_id' => $officeId,
        'name' => 'Vacation Leave',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
    ];

    $outOfScope = $this->postJson('/api/v1/office/leave-types', $payload($cebu->id))
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/office/leave-types', $payload((string) Str::uuid7()))
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('leave_types', 0);
});

it('400s a missing name', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/leave-types', [
        'office_id' => $manila->id,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('400s a negative max_carryover_minutes', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/office/leave-types', [
        'office_id' => $manila->id,
        'name' => 'Vacation Leave',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => -1,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('stores an explicit null code and null max_carryover_minutes as NULL, not \'\' or 0', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/office/leave-types', [
        'office_id' => $manila->id,
        'name' => 'Vacation Leave',
        'code' => null,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => null,
    ])->assertCreated();

    $leaveTypeId = $response->json('data.id');

    expect($response->json('data.code'))->toBeNull()
        ->and($response->json('data.max_carryover_minutes'))->toBeNull();

    $stored = LeaveType::query()->findOrFail($leaveTypeId);

    expect($stored->code)->toBeNull()
        ->and($stored->max_carryover_minutes)->toBeNull();
});

// --- Update -----------------------------------------------------------------

it('lets an HR admin update a leave type for an office they administer, and logs it', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create([
        'name' => 'Vacation Leave',
        'is_paid' => true,
        'is_active' => true,
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->patchJson("/api/v1/office/leave-types/{$leaveType->id}", [
        'name' => 'Vacation Leave (Retired)',
        'code' => 'VL',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'is_active' => false,
    ])->assertOk();

    expect($response->json('data'))->toBe([
        'id' => $leaveType->id,
        'office_id' => $manila->id,
        'name' => 'Vacation Leave (Retired)',
        'code' => 'VL',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => null,
        'is_active' => false,
    ]);

    $this->assertDatabaseHas('leave_types', [
        'id' => $leaveType->id,
        'name' => 'Vacation Leave (Retired)',
        'is_active' => false,
    ]);

    $activity = Activity::query()
        ->where('subject_id', $leaveType->id)
        ->where('event', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($hrUser->id)
        ->and($activity->subject_type)->toBe(LeaveType::class);
});

it('404s updating a leave type belonging to an office the admin does not administer, identically to a fabricated leave type', function (): void {
    $manila = leaveTypeOffice();
    $cebu = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);
    $outOfScopeType = LeaveType::factory()->for($cebu, 'office')->create();

    Sanctum::actingAs($hrUser);

    $payload = [
        'name' => 'Vacation Leave',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
    ];

    $outOfScope = $this->patchJson("/api/v1/office/leave-types/{$outOfScopeType->id}", $payload)
        ->assertStatus(404);

    $fabricated = $this->patchJson('/api/v1/office/leave-types/'.(string) Str::uuid7(), $payload)
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('leave_types', [
        'id' => $outOfScopeType->id,
        'name' => $outOfScopeType->name,
    ]);
});

it('400s updating a leave type with a negative max_carryover_minutes', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create();

    Sanctum::actingAs($hrUser);

    $this->patchJson("/api/v1/office/leave-types/{$leaveType->id}", [
        'name' => 'Vacation Leave',
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => -1,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('stores an explicit null code and null max_carryover_minutes on update as NULL, not \'\' or 0', function (): void {
    $manila = leaveTypeOffice();
    $hrUser = leaveHrAdminOf($manila);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create([
        'code' => 'VL',
        'max_carryover_minutes' => 4800,
    ]);

    Sanctum::actingAs($hrUser);

    $response = $this->patchJson("/api/v1/office/leave-types/{$leaveType->id}", [
        'name' => 'Vacation Leave',
        'code' => null,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => null,
    ])->assertOk();

    expect($response->json('data.code'))->toBeNull()
        ->and($response->json('data.max_carryover_minutes'))->toBeNull();

    $leaveType->refresh();

    expect($leaveType->code)->toBeNull()
        ->and($leaveType->max_carryover_minutes)->toBeNull();
});
