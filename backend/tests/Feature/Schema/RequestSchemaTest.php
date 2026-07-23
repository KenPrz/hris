<?php

declare(strict_types=1);

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;
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
    expect(array_map(fn ($c) => $c->value, RequestType::cases()))->toBe(['attendance_adjustment'])
        ->and(array_map(fn ($c) => $c->value, RequestState::cases()))->toBe(['pending', 'approved', 'rejected', 'cancelled']);

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
