<?php

declare(strict_types=1);

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores a request with typed enums', function (): void {
    $employee = Employee::factory()->create();

    $request = Request::factory()->create([
        'employee_id' => $employee->id,
        'type' => RequestType::AttendanceAdjustment,
        'state' => RequestState::Pending,
        'note' => 'Forgot to clock out.',
    ]);

    $fresh = $request->fresh();
    expect($fresh->type)->toBe(RequestType::AttendanceAdjustment)
        ->and($fresh->state)->toBe(RequestState::Pending)
        ->and($fresh->isPending())->toBeTrue()
        ->and($fresh->employee->is($employee))->toBeTrue();
});

it('requires a note', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'pending',
        'note' => null,             // NOT NULL
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects a state outside the CHECK', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'half_approved',   // not in the CHECK
        'note' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps the CHECK lists in sync with the enum cases', function (): void {
    // Golden list — documents the intended values and catches an enum rename.
    expect(array_map(fn ($c) => $c->value, RequestType::cases()))->toBe(['attendance_adjustment', 'leave', 'overtime'])
        ->and(array_map(fn ($c) => $c->value, RequestState::cases()))->toBe(['pending', 'manager_approved', 'approved', 'rejected', 'cancelled']);

    // Live-constraint parity — reads the actual CHECK from Postgres so the migration's
    // value list cannot drift from the enum independently (adding a case without widening
    // the CHECK, or vice versa, fails here).
    $checkValues = function (string $constraint): array {
        $def = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
            [$constraint],
        );

        expect($def)->not->toBeNull("constraint {$constraint} should exist");

        preg_match_all("/'([^']+)'/", $def->def, $m);

        return array_values(array_unique($m[1]));
    };

    $sorted = function (array $v): array {
        sort($v);

        return $v;
    };

    expect($sorted($checkValues('requests_type_check')))
        ->toBe($sorted(array_map(fn ($c) => $c->value, RequestType::cases())))
        ->and($sorted($checkValues('requests_state_check')))
        ->toBe($sorted(array_map(fn ($c) => $c->value, RequestState::cases())));
});

it('rejects a rejected request with no decision_note at the DB level', function (): void {
    $employee = Employee::factory()->create();

    $insert = fn (string $state, ?string $note) => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => $state,
        'note' => 'x',
        'decision_note' => $note,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Valid rows first — a CHECK violation aborts the whole Postgres transaction (and the
    // test's RefreshDatabase wraps one), so the refused insert has to come last.
    // A rejection WITH a note is fine; a non-rejected row may leave the note null.
    expect($insert('rejected', 'Not enough evidence.'))->toBeTrue();
    expect($insert('pending', null))->toBeTrue();

    // A rejection with no decision_note is refused by requests_rejected_note_check.
    expect(fn () => $insert('rejected', null))->toThrow(Illuminate\Database\QueryException::class);
});

it('admits the manager_approved intermediate state and round-trips the hop-1 decision columns', function (): void {
    $employee = Employee::factory()->create();
    $manager = User::factory()->create();

    $id = (string) Illuminate\Support\Str::uuid7();
    $managerDecidedAt = now();

    DB::table('requests')->insert([
        'id' => $id,
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'manager_approved',
        'note' => 'x',
        'manager_decided_by' => $manager->id,
        'manager_decided_at' => $managerDecidedAt,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = DB::table('requests')->where('id', $id)->first();
    expect($row->state)->toBe('manager_approved')
        ->and($row->manager_decided_by)->toBe($manager->id)
        ->and($row->manager_decided_at)->not->toBeNull();
});

it('still rejects a state outside the widened CHECK', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'bogus',
        'note' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('accepts the leave request type at the DB level', function (): void {
    $employee = Employee::factory()->create();

    $inserted = DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'leave',
        'state' => 'pending',
        'note' => 'VL, July 30-31.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($inserted)->toBeTrue();
});

it('accepts the overtime request type at the DB level', function (): void {
    $employee = Employee::factory()->create();

    $inserted = DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'overtime',
        'state' => 'pending',
        'note' => 'Requesting OT, July 30, 6-9pm.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($inserted)->toBeTrue();
});

it('admits overtime in the requests type check', function (): void {
    // The CHECK list must equal RequestType::cases() — pin them together.
    $checked = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'requests_type_check'"
    )->def;
    foreach (RequestType::cases() as $case) {
        expect($checked)->toContain("'{$case->value}'");
    }
});

it('rejects a type outside the widened CHECK', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'bogus',
        'state' => 'pending',
        'note' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
