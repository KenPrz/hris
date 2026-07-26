<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M6b-a Task 6: derived balance reads. Balances are never stored — every assertion here
| re-derives from the ledger, and a re-grant changing the number with no migration/stored
| field is the point (see the "recomputed" test below).
*/

function balanceOffice(int $minutesPerLeaveDay = 480): Office
{
    return Office::factory()->create(['minutes_per_leave_day' => $minutesPerLeaveDay]);
}

function grant(Employee $employee, LeaveType $leaveType, int $minutes, User $actor): void
{
    \App\Models\LeaveLedger::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => $minutes,
        'source' => 'manual_grant',
        'created_by' => $actor->id,
    ]);
}

// --- /me/leave -------------------------------------------------------------

it('lists derived balances for the caller after two grants, with a readable decomposition', function (): void {
    $office = balanceOffice(480);
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    grant($employee, $leaveType, 480, $user);
    grant($employee, $leaveType, 240, $user);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/leave')->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    $row = $data[0];

    expect($row['leave_type']['id'])->toBe($leaveType->id)
        ->and($row['balance_minutes'])->toBe(720)
        ->and($row['balance_readable'])->toBe(['days' => 1, 'hours' => 4, 'minutes' => 0]);
});

it('shows 0 for an active deducts_balance type with no ledger rows', function (): void {
    $office = balanceOffice(480);
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true, 'name' => 'Untouched Leave']);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/leave')->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['balance_minutes'])->toBe(0)
        ->and($data[0]['balance_readable'])->toBe(['days' => 0, 'hours' => 0, 'minutes' => 0]);
});

it('excludes inactive and non-balance-deducting leave types', function (): void {
    $office = balanceOffice(480);
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true, 'is_active' => false]);
    LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => false, 'is_active' => true]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/leave')->assertOk()->json('data');

    expect($data)->toBe([]);
});

it('422s /me/leave for a user with no employee', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/leave')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});

it('recomputes the balance from the ledger on every read — no stored field', function (): void {
    $office = balanceOffice(480);
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    grant($employee, $leaveType, 480, $user);

    Sanctum::actingAs($user);

    $first = $this->getJson('/api/v1/me/leave')->assertOk()->json('data');
    expect($first[0]['balance_minutes'])->toBe(480);

    grant($employee, $leaveType, 480, $user);

    $second = $this->getJson('/api/v1/me/leave')->assertOk()->json('data');
    expect($second[0]['balance_minutes'])->toBe(960);
});

// --- /employees/{id}/leave --------------------------------------------------

it('lets a manager read their direct report\'s balances', function (): void {
    $office = balanceOffice(480);
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);
    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    grant($report, $leaveType, 480, $managerUser);

    Sanctum::actingAs($managerUser);

    $data = $this->getJson("/api/v1/employees/{$report->id}/leave")->assertOk()->json('data');

    expect($data[0]['balance_minutes'])->toBe(480);
});

it('lets office HR read a member\'s balances', function (): void {
    $office = balanceOffice(480);
    $hrUser = User::factory()->create();
    $hrUser->hrAdminOffices()->attach($office->id);
    $worker = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    grant($worker, $leaveType, 240, $hrUser);

    Sanctum::actingAs($hrUser);

    $data = $this->getJson("/api/v1/employees/{$worker->id}/leave")->assertOk()->json('data');

    expect($data[0]['balance_minutes'])->toBe(240);
});

it('404s /employees/{id}/leave for an unrelated user, EmployeeScope', function (): void {
    $manila = balanceOffice();
    $cebu = balanceOffice();
    $unrelatedUser = User::factory()->create();
    Employee::factory()->for($unrelatedUser)->create(['current_office_id' => $manila->id]);
    $stranger = Employee::factory()->create(['current_office_id' => $cebu->id]);

    Sanctum::actingAs($unrelatedUser);

    $this->getJson("/api/v1/employees/{$stranger->id}/leave")->assertStatus(404);
});

it('404s /employees/{id}/leave for a fabricated employee id, identically to an out-of-scope one', function (): void {
    $office = balanceOffice();
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/employees/'.\Illuminate\Support\Str::uuid7().'/leave')->assertStatus(404);
});
