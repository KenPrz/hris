<?php

declare(strict_types=1);

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\InvalidAdjustmentTarget;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** The rows attendance_logs would show if annulments were actually deletes. */
function effectiveLedgerLogIds(): array
{
    return AttendanceLog::query()
        ->whereNotIn('id', AttendanceAnnulment::query()->select('attendance_log_id'))
        ->pluck('id')
        ->all();
}

function adjustmentEmployee(): Employee
{
    $office = Office::factory()->create(['ip_allowlist' => null]);

    return Employee::factory()->create(['current_office_id' => $office->id]);
}

it('applies an add adjustment by recording a new punch through RecordPunch', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $request = Request::factory()->for($employee)->create();
    $detail = AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::In,
        'punched_at' => '2026-07-20T08:00:00Z',
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    expect(AttendanceLog::count())->toBe(1);

    $log = AttendanceLog::query()->first();
    expect($log->employee_id)->toBe($employee->id)
        ->and($log->source)->toBe(PunchSource::Adjustment)
        ->and($log->recorded_by)->toBe($approver->id)
        ->and($log->direction)->toBe(PunchDirection::In)
        ->and($log->punched_at->utc()->toDateTimeString())->toBe('2026-07-20 08:00:00');
});

it('applies a void adjustment by annulling the target, leaving the original row untouched', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $target = AttendanceLog::factory()->create(['employee_id' => $employee->id]);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $target->id,
        'direction' => null,
        'punched_at' => null,
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    expect(AttendanceAnnulment::count())->toBe(1);
    $annulment = AttendanceAnnulment::query()->first();
    expect($annulment->attendance_log_id)->toBe($target->id)
        ->and($annulment->request_id)->toBe($request->id);

    // The append-only ledger is never mutated: the original row still exists, exactly.
    expect(AttendanceLog::count())->toBe(1)
        ->and(AttendanceLog::query()->find($target->id))->not->toBeNull();

    // ...but the effective ledger (logs minus annulments) no longer surfaces it.
    expect(effectiveLedgerLogIds())->not->toContain($target->id);
});

it('applies an amend adjustment by annulling the target and recording the corrected punch', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $target = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'direction' => PunchDirection::Out,
    ]);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Amend,
        'target_log_id' => $target->id,
        'direction' => PunchDirection::Out,
        'punched_at' => '2026-07-20T18:00:00Z',
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    expect(AttendanceAnnulment::count())->toBe(1)
        ->and(AttendanceAnnulment::query()->first()->attendance_log_id)->toBe($target->id)
        ->and(AttendanceLog::count())->toBe(2)   // original + corrected, never overwritten
        ->and(AttendanceLog::query()->find($target->id))->not->toBeNull();

    $corrected = AttendanceLog::query()->where('id', '!=', $target->id)->first();
    expect($corrected->source)->toBe(PunchSource::Adjustment)
        ->and($corrected->recorded_by)->toBe($approver->id)
        ->and($corrected->punched_at->utc()->toDateTimeString())->toBe('2026-07-20 18:00:00');

    $effective = effectiveLedgerLogIds();
    expect($effective)->not->toContain($target->id)
        ->and($effective)->toContain($corrected->id);
});

it('rejects a void whose target belongs to a different employee', function (): void {
    $employee = adjustmentEmployee();
    $otherEmployee = Employee::factory()->create();
    $approver = User::factory()->create();

    $target = AttendanceLog::factory()->create(['employee_id' => $otherEmployee->id]);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $target->id,
    ]);

    expect(fn () => app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id))
        ->toThrow(InvalidAdjustmentTarget::class);

    expect(AttendanceAnnulment::count())->toBe(0);
});

it('rejects a void whose target is already annulled', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $target = AttendanceLog::factory()->create(['employee_id' => $employee->id]);
    AttendanceAnnulment::factory()->create(['attendance_log_id' => $target->id]);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $target->id,
    ]);

    expect(fn () => app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id))
        ->toThrow(InvalidAdjustmentTarget::class);

    expect(AttendanceAnnulment::count())->toBe(1);   // still just the pre-existing one
});

it('rejects a void with no target (the FK forbids a dangling id, so null is the reachable "missing" case)', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => null,
    ]);

    expect(fn () => app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id))
        ->toThrow(InvalidAdjustmentTarget::class);

    expect(AttendanceAnnulment::count())->toBe(0);
});

it('renders invalid_adjustment_target as a 422', function (): void {
    $exception = new InvalidAdjustmentTarget('The punch to correct is missing or not yours.');

    expect($exception->errorCode())->toBe('invalid_adjustment_target')
        ->and($exception->httpStatus())->toBe(422);
});

it('stores the correct UTC instant for an add supplied with a non-UTC offset', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $request = Request::factory()->for($employee)->create();
    $detail = AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'direction' => PunchDirection::In,
        'punched_at' => now(),   // placeholder; overwritten below with the offset-bearing literal
    ]);

    // Eloquent's datetime cast formats via Y-m-d H:i:s (no offset) on write, which would
    // silently drop a non-UTC offset before it ever reaches Postgres — the very bug
    // RecordPunch's ->utc() exists to avoid. Writing the raw literal through the query
    // builder instead lets Postgres itself — which does understand ISO-8601 offsets —
    // parse it, so the fixture holds the intended instant, not a corrupted one.
    DB::table('attendance_adjustment_details')
        ->where('request_id', $request->id)
        ->update(['punched_at' => '2026-07-01T08:00:00+08:00']);   // = 2026-07-01 00:00:00Z

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    $log = AttendanceLog::query()->first();
    $stored = DB::table('attendance_logs')->where('id', $log->id)->value('punched_at');

    expect($log->fresh()->punched_at->utc()->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($stored)->toBe('2026-07-01 00:00:00+00');
});

it('stores the correct UTC instant for the corrected punch in an amend with a non-UTC offset', function (): void {
    $employee = adjustmentEmployee();
    $approver = User::factory()->create();

    $target = AttendanceLog::factory()->create(['employee_id' => $employee->id]);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Amend,
        'target_log_id' => $target->id,
        'direction' => PunchDirection::Out,
        'punched_at' => now(),   // placeholder; overwritten below with the offset-bearing literal
    ]);

    // See the "add" UTC test above for why this goes through the query builder rather
    // than the Eloquent cast.
    DB::table('attendance_adjustment_details')
        ->where('request_id', $request->id)
        ->update(['punched_at' => '2026-07-01T18:00:00+08:00']);   // = 2026-07-01 10:00:00Z

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    $corrected = AttendanceLog::query()->where('id', '!=', $target->id)->first();
    $stored = DB::table('attendance_logs')->where('id', $corrected->id)->value('punched_at');

    expect($corrected->fresh()->punched_at->utc()->toDateTimeString())->toBe('2026-07-01 10:00:00')
        ->and($stored)->toBe('2026-07-01 10:00:00+00');
});
