<?php

declare(strict_types=1);

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('round-trips an adjustment detail with its enum operation and nullable fields', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();
    $log = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
    ]);
    $request = Request::factory()->create(['employee_id' => $employee->id]);

    AttendanceAdjustmentDetail::query()->create([
        'request_id' => $request->id,
        'operation' => AdjustmentOperation::Amend,
        'target_log_id' => $log->id,
        'direction' => PunchDirection::In,
        'punched_at' => now(),
    ]);

    $fresh = AttendanceAdjustmentDetail::query()->find($request->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->request_id)->toBe($request->id)
        ->and($fresh->operation)->toBe(AdjustmentOperation::Amend)
        ->and($fresh->target_log_id)->toBe($log->id)
        ->and($fresh->direction)->toBe(PunchDirection::In)
        ->and($fresh->request->is($request))->toBeTrue()
        ->and($fresh->targetLog->is($log))->toBeTrue();
});

it('round-trips an adjustment detail with nullable fields left null', function (): void {
    $employee = Employee::factory()->create();
    $request = Request::factory()->create(['employee_id' => $employee->id]);

    AttendanceAdjustmentDetail::query()->create([
        'request_id' => $request->id,
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => null,
        'punched_at' => null,
    ]);

    $fresh = AttendanceAdjustmentDetail::query()->find($request->id);

    expect($fresh->operation)->toBe(AdjustmentOperation::Add)
        ->and($fresh->target_log_id)->toBeNull()
        ->and($fresh->direction)->toBeNull()
        ->and($fresh->punched_at)->toBeNull();
});

it('rejects a second detail for the same request (true 1:1 PK)', function (): void {
    $employee = Employee::factory()->create();
    $request = Request::factory()->create(['employee_id' => $employee->id]);

    AttendanceAdjustmentDetail::query()->create([
        'request_id' => $request->id,
        'operation' => AdjustmentOperation::Add,
    ]);

    expect(fn () => AttendanceAdjustmentDetail::query()->create([
        'request_id' => $request->id,
        'operation' => AdjustmentOperation::Void,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects an operation outside the CHECK via a raw insert', function (): void {
    $employee = Employee::factory()->create();
    $request = Request::factory()->create(['employee_id' => $employee->id]);

    expect(fn () => DB::table('attendance_adjustment_details')->insert([
        'request_id' => $request->id,
        'operation' => 'delete',   // not in ('add','void','amend')
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('round-trips an annulment', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();
    $log = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
    ]);
    $request = Request::factory()->create(['employee_id' => $employee->id]);

    $annulment = AttendanceAnnulment::query()->create([
        'attendance_log_id' => $log->id,
        'request_id' => $request->id,
    ]);

    $fresh = $annulment->fresh();

    expect($fresh->attendance_log_id)->toBe($log->id)
        ->and($fresh->request_id)->toBe($request->id)
        ->and($fresh->attendanceLog->is($log))->toBeTrue()
        ->and($fresh->request->is($request))->toBeTrue()
        ->and($fresh->id)->toBeString();
});

it('rejects a second annulment of the same punch', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();
    $log = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
    ]);
    $requestOne = Request::factory()->create(['employee_id' => $employee->id]);
    $requestTwo = Request::factory()->create(['employee_id' => $employee->id]);

    AttendanceAnnulment::query()->create([
        'attendance_log_id' => $log->id,
        'request_id' => $requestOne->id,
    ]);

    expect(fn () => AttendanceAnnulment::query()->create([
        'attendance_log_id' => $log->id,
        'request_id' => $requestTwo->id,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('now accepts source = adjustment on attendance_logs (the widened CHECK)', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();

    DB::table('attendance_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'punched_at' => now(),
        'direction' => 'in',
        'source' => 'adjustment',
        'verification' => 'verified',
        'created_at' => now(),
    ]);

    expect(DB::table('attendance_logs')->where('source', 'adjustment')->count())->toBe(1);
});

it('keeps AdjustmentOperation cases as add, void, amend', function (): void {
    expect(array_map(fn ($c) => $c->value, AdjustmentOperation::cases()))
        ->toBe(['add', 'void', 'amend']);
});

it('keeps the CHECK value lists in sync with the enum cases', function (): void {
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

    expect($sorted($checkValues('attendance_adjustment_details_operation_check')))
        ->toBe($sorted(array_map(fn ($c) => $c->value, AdjustmentOperation::cases())))
        ->and($sorted($checkValues('attendance_logs_source_check')))
        ->toBe($sorted(array_map(fn ($c) => $c->value, PunchSource::cases())));
});
