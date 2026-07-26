<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('round-trips a credit row', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();
    $user = User::factory()->create();

    $entry = LeaveLedger::query()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 2400,
        'reason' => 'Annual grant',
        'source' => 'manual_grant',
        'request_id' => null,
        'created_by' => $user->id,
    ]);

    $fresh = LeaveLedger::query()->find($entry->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->employee_id)->toBe($employee->id)
        ->and($fresh->leave_type_id)->toBe($leaveType->id)
        ->and($fresh->entry_type)->toBe('credit')
        ->and($fresh->minutes)->toBe(2400)
        ->and($fresh->reason)->toBe('Annual grant')
        ->and($fresh->source)->toBe('manual_grant')
        ->and($fresh->request_id)->toBeNull()
        ->and($fresh->created_by)->toBe($user->id)
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->employee->is($employee))->toBeTrue()
        ->and($fresh->leaveType->is($leaveType))->toBeTrue()
        ->and($fresh->createdBy->is($user))->toBeTrue();
});

it('has no updated_at column — the ledger is append-only', function (): void {
    expect(Schema::hasColumn('leave_ledger', 'updated_at'))->toBeFalse();
});

it('rejects an entry_type outside the CHECK via a raw insert', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('leave_ledger')->insert([
        'id' => (string) Str::uuid7(),
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'foo',
        'minutes' => 480,
        'reason' => 'bad entry_type',
        'source' => 'manual_grant',
        'created_by' => $user->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a source outside the CHECK via a raw insert', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('leave_ledger')->insert([
        'id' => (string) Str::uuid7(),
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 480,
        'reason' => 'bad source',
        'source' => 'foo',
        'created_by' => $user->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects minutes = 0 via the CHECK constraint', function (): void {
    $employee = Employee::factory()->create();
    $leaveType = LeaveType::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('leave_ledger')->insert([
        'id' => (string) Str::uuid7(),
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 0,
        'reason' => 'zero minutes',
        'source' => 'manual_grant',
        'created_by' => $user->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
