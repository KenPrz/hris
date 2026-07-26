<?php

declare(strict_types=1);

use App\Domain\Leave\LeaveBalances;
use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('nets three credits and one debit for an employee/type', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();
    $user = User::factory()->create();

    $entry = fn (string $entryType, int $minutes) => LeaveLedger::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => $entryType,
        'minutes' => $minutes,
        'source' => 'manual_grant',
        'created_by' => $user->id,
    ]);

    $entry('credit', 480);
    $entry('credit', 480);
    $entry('credit', 480);
    $entry('debit', 240);

    $balances = LeaveBalances::forEmployee($employee);

    expect($balances[$leaveType->id])->toBe(1200);
});

it('keeps a second leave type independent', function (): void {
    $employee = Employee::factory()->create();
    $vl = LeaveType::factory()->create(['code' => 'vl']);
    $sl = LeaveType::factory()->create(['code' => 'sl']);
    $user = User::factory()->create();

    LeaveLedger::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $vl->id,
        'entry_type' => 'credit',
        'minutes' => 960,
        'created_by' => $user->id,
    ]);
    LeaveLedger::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $sl->id,
        'entry_type' => 'credit',
        'minutes' => 480,
        'created_by' => $user->id,
    ]);
    LeaveLedger::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $sl->id,
        'entry_type' => 'debit',
        'minutes' => 120,
        'created_by' => $user->id,
    ]);

    $balances = LeaveBalances::forEmployee($employee);

    expect($balances[$vl->id])->toBe(960)
        ->and($balances[$sl->id])->toBe(360);
});

it('returns an empty array for an employee with no ledger rows', function (): void {
    $employee = Employee::factory()->create();

    $balances = LeaveBalances::forEmployee($employee);

    expect($balances)->toBe([]);
});
