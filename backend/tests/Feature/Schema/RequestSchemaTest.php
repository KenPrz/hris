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
    expect(array_map(fn ($c) => $c->value, RequestType::cases()))->toBe(['attendance_adjustment'])
        ->and(array_map(fn ($c) => $c->value, RequestState::cases()))->toBe(['pending', 'approved', 'rejected', 'cancelled']);
});
