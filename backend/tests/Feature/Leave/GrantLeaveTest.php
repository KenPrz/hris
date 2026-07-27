<?php

declare(strict_types=1);

use App\Domain\Leave\LeaveBalances;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M6b-a Task 5: HR manual grant — one logged, append-only credit row. Mirrors
| LeaveTypeConfigTest's office-scoped 404-not-403 pattern, but scoped via
| OfficeScope::administers against the EMPLOYEE's current office, not the office-id
| body param those endpoints take — a grant targets a person, not a config resource.
*/

function grantOffice(int $minutesPerLeaveDay = 480): Office
{
    return Office::factory()->create(['minutes_per_leave_day' => $minutesPerLeaveDay]);
}

function grantHrAdminOf(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

// --- Happy path -------------------------------------------------------------

it('lets HR grant 5 days to an employee in their office as one credit row', function (): void {
    $manila = grantOffice(480);
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $response = $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => 'Manual back-fill of unused 2025 VL',
    ])->assertCreated();

    $entryId = $response->json('data.id');

    expect($response->json('data.minutes'))->toBe(2400)
        ->and($response->json('data.entry_type'))->toBe('credit')
        ->and($response->json('data.source'))->toBe('manual_grant')
        ->and($response->json('data.reason'))->toBe('Manual back-fill of unused 2025 VL')
        ->and($response->json('data.created_by'))->toBe($hrUser->id);

    $this->assertDatabaseHas('leave_ledger', [
        'id' => $entryId,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 2400,
        'source' => 'manual_grant',
        'created_by' => $hrUser->id,
    ]);
    $this->assertDatabaseCount('leave_ledger', 1);

    $balances = LeaveBalances::forEmployee($employee->fresh());

    expect($balances[$leaveType->id])->toBe(2400);
});

it('adds a second row on a re-grant, never editing the first (append-only)', function (): void {
    $manila = grantOffice(480);
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $payload = [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => 'First grant',
    ];

    $first = $this->postJson('/api/v1/leave/grants', $payload)->assertCreated();

    $second = $this->postJson('/api/v1/leave/grants', array_merge($payload, ['reason' => 'Second grant']))
        ->assertCreated();

    expect($first->json('data.id'))->not->toBe($second->json('data.id'));

    $this->assertDatabaseCount('leave_ledger', 2);

    $balances = LeaveBalances::forEmployee($employee->fresh());

    expect($balances[$leaveType->id])->toBe(4800);
});

// --- Guards -------------------------------------------------------------

it('404s granting to an employee in a non-administered office, identically to a fabricated employee', function (): void {
    $manila = grantOffice();
    $cebu = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $cebu->id]);
    $leaveType = LeaveType::factory()->for($cebu, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $payload = fn (string $employeeId): array => [
        'employee_id' => $employeeId,
        'leave_type_id' => $leaveType->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => 'Should not land',
    ];

    $outOfScope = $this->postJson('/api/v1/leave/grants', $payload($employee->id))
        ->assertStatus(404);

    $fabricated = $this->postJson('/api/v1/leave/grants', $payload((string) Str::uuid7()))
        ->assertStatus(404);

    $outOfScope->assertExactJson($fabricated->json());
    $outOfScope->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('leave_ledger', 0);
});

it('422s granting an event (deducts_balance=false) leave type', function (): void {
    $manila = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $maternity = LeaveType::factory()->for($manila, 'office')->create([
        'name' => 'Maternity Leave',
        'deducts_balance' => false,
    ]);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $maternity->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => 'Cannot bank an event type',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leave_type_not_grantable');

    $this->assertDatabaseCount('leave_ledger', 0);
});

it('422s granting into an inactive (retired) leave type', function (): void {
    $manila = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $retired = LeaveType::factory()->for($manila, 'office')->create([
        'deducts_balance' => true,
        'is_active' => false,
    ]);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $retired->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => 'Cannot grant into a retired type',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leave_type_inactive');

    $this->assertDatabaseCount('leave_ledger', 0);
});

it('400s an empty reason', function (): void {
    $manila = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'amount' => 5,
        'unit' => 'day',
        'reason' => '',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('leave_ledger', 0);
});

it('400s an amount of 0', function (): void {
    $manila = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'amount' => 0,
        'unit' => 'day',
        'reason' => 'Zero is not a grant',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('leave_ledger', 0);
});

it('400s an unrecognized unit', function (): void {
    $manila = grantOffice();
    $hrUser = grantHrAdminOf($manila);
    $employee = Employee::factory()->create(['current_office_id' => $manila->id]);
    $leaveType = LeaveType::factory()->for($manila, 'office')->create(['deducts_balance' => true]);

    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/leave/grants', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'amount' => 5,
        'unit' => 'fortnight',
        'reason' => 'Bad unit',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('leave_ledger', 0);
});
