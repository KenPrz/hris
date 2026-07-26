<?php

declare(strict_types=1);

use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('round-trips the flags and max_carryover_minutes', function (): void {
    $office = Office::factory()->create();

    $leaveType = LeaveType::factory()->create([
        'office_id' => $office->id,
        'name' => 'Sick Leave',
        'code' => 'sil',
        'is_paid' => true,
        'requires_attachment' => true,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => 2400,
        'is_active' => true,
    ]);

    $fresh = $leaveType->fresh();

    expect($fresh->name)->toBe('Sick Leave')
        ->and($fresh->code)->toBe('sil')
        ->and($fresh->is_paid)->toBeTrue()
        ->and($fresh->requires_attachment)->toBeTrue()
        ->and($fresh->deducts_balance)->toBeTrue()
        ->and($fresh->is_cash_convertible)->toBeFalse()
        ->and($fresh->max_carryover_minutes)->toBe(2400)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->office->is($office))->toBeTrue();
});

it('round-trips a null code and null max_carryover_minutes', function (): void {
    $office = Office::factory()->create();

    $leaveType = LeaveType::factory()->create([
        'office_id' => $office->id,
        'code' => null,
        'max_carryover_minutes' => null,
    ]);

    $fresh = $leaveType->fresh();

    expect($fresh->code)->toBeNull()
        ->and($fresh->max_carryover_minutes)->toBeNull();
});

it('rejects a second leave type with the same office and code', function (): void {
    $office = Office::factory()->create();

    $insert = fn (string $code) => DB::table('leave_types')->insert([
        'id' => (string) Str::uuid7(),
        'office_id' => $office->id,
        'name' => 'Sick Leave',
        'code' => $code,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => null,
        'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($insert('sil'))->toBeTrue();
    expect(fn () => $insert('sil'))->toThrow(QueryException::class);
});

it('allows two null-code leave types in the same office', function (): void {
    $office = Office::factory()->create();

    $insert = fn (string $name) => DB::table('leave_types')->insert([
        'id' => (string) Str::uuid7(),
        'office_id' => $office->id,
        'name' => $name,
        'code' => null,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => null,
        'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($insert('Ad-hoc Leave A'))->toBeTrue();
    expect($insert('Ad-hoc Leave B'))->toBeTrue();
});

it('rejects a negative max_carryover_minutes via the CHECK constraint', function (): void {
    $office = Office::factory()->create();

    $insert = fn (int $maxCarryover) => DB::table('leave_types')->insert([
        'id' => (string) Str::uuid7(),
        'office_id' => $office->id,
        'name' => 'Vacation Leave',
        'code' => null,
        'is_paid' => true,
        'requires_attachment' => false,
        'deducts_balance' => true,
        'is_cash_convertible' => false,
        'max_carryover_minutes' => $maxCarryover,
        'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Valid row first — a CHECK violation aborts the whole Postgres transaction (and
    // RefreshDatabase wraps one), so the refused insert has to come last.
    expect($insert(0))->toBeTrue();
    expect(fn () => $insert(-1))->toThrow(QueryException::class);
});
