<?php

declare(strict_types=1);

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Pay\DayType;
use App\Domain\Schedule\Weekday;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\PayRule;
use App\Models\Request;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
| M5a Task 6: the SYNCHRONOUS after-commit trigger. RecordPunch/ApplyAttendanceAdjustment
| register their recompute via DB::afterCommit — see each action's docblock for why that's
| the right idiom (a compute failure can't roll back an already-durable punch/annulment).
|
| Under RefreshDatabase, Laravel's testing-specific DatabaseTransactionsManager (see
| Illuminate\Foundation\Testing\DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted)
| deliberately fires afterCommit callbacks once a nested transaction returns to the test's
| OWN wrapping level (not just real level 0) — precisely so this pattern is testable without
| a genuinely separate connection. So this file needs no special non-transactional handling;
| it mirrors the existing Attendance suite's plain RefreshDatabase style.
*/

/** Mon-Fri 08:00-18:00 (480-1080 minutes, 60m break), Sat/Sun rest — the office default. */
function onWriteOffice(): Office
{
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Standard']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id,
            'weekday' => $wd,
            'is_rest' => $rest,
            'start_minute' => $rest ? null : 480,
            'end_minute' => $rest ? null : 1080,
            'break_minutes' => $rest ? null : 60,
        ]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    return $office;
}

/** An employee with a resolvable EmploymentRecord (office/department/art82) effective from 2026-01-01. */
function onWriteEmployee(Office $office): Employee
{
    $department = Department::factory()->create(['office_id' => $office->id]);

    $employee = Employee::factory()->create([
        'organization_id' => $office->organization_id,
        'current_office_id' => $office->id,
        'current_department_id' => $department->id,
    ]);

    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'department_id' => $department->id,
        'effective_from' => '2026-01-01',
        'is_art82_exempt' => false,
    ]);

    return $employee;
}

/** A pay-rules version at the statutory floor, effective from 2026-01-01 — enough to price a plain ordinary day. */
function onWritePayRule(): PayRule
{
    $floors = config('hris.pay_floors');

    $rule = PayRule::create([
        'effective_from' => '2026-01-01',
        'overtime_ordinary_bp' => $floors['overtime_ordinary'],
        'overtime_premium_bp' => $floors['overtime_premium'],
        'night_diff_bp' => $floors['night_diff'],
    ]);

    foreach (DayType::cases() as $dayType) {
        [$workedBp, $workedRestBp] = $floors['worked'][$dayType->value];
        $unworkedBp = $floors['unworked'][$dayType->value];

        $rule->dayRates()->create([
            'day_type' => $dayType->value,
            'worked_bp' => $workedBp,
            'worked_rest_bp' => $workedRestBp,
            'unworked_bp' => $unworkedBp,
        ]);
    }

    return $rule;
}

/** Records a manual, self-verifying punch (no IP) at local $time on $date in $office's timezone. */
function onWritePunch(Employee $employee, Office $office, string $date, string $time, PunchDirection $direction): AttendanceLog
{
    return app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: $direction,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse("{$date} {$time}", $office->timezone)->utc(),
        recordedBy: null,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));
}

it('recomputes a fresh, computed summary after recording an in+out punch', function (): void {
    $office = onWriteOffice();
    $employee = onWriteEmployee($office);
    $payRule = onWritePayRule();

    $date = '2026-08-03'; // Monday
    onWritePunch($employee, $office, $date, '08:00', PunchDirection::In);
    onWritePunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', $date)
        ->with('lines')
        ->first();

    expect($summary)->not->toBeNull()
        ->and($summary->status)->toBe('computed')
        ->and($summary->is_incomplete)->toBeFalse()
        ->and($summary->worked_minutes)->toBe(480)
        ->and($summary->rule_version_id)->toBe($payRule->id)
        ->and($summary->lines)->toHaveCount(1);
});

it('leaves an is_incomplete summary after a single in-punch with no matching out', function (): void {
    $office = onWriteOffice();
    $employee = onWriteEmployee($office);
    onWritePayRule();

    $date = '2026-08-04'; // Tuesday
    onWritePunch($employee, $office, $date, '08:00', PunchDirection::In);
    // no matching out-punch

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', $date)
        ->first();

    expect($summary)->not->toBeNull()
        ->and($summary->is_incomplete)->toBeTrue()
        ->and($summary->worked_minutes)->toBe(0)
        ->and($summary->rule_version_id)->toBeNull();
});

it('flips an incomplete day to a computed worked total after an approved add adjustment', function (): void {
    $office = onWriteOffice();
    $employee = onWriteEmployee($office);
    $payRule = onWritePayRule();
    $approver = User::factory()->create();

    $date = '2026-08-05'; // Wednesday
    onWritePunch($employee, $office, $date, '08:00', PunchDirection::In);

    $before = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();
    expect($before)->not->toBeNull()
        ->and($before->is_incomplete)->toBeTrue();

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::Out,
        'punched_at' => Carbon::parse("{$date} 17:00", $office->timezone)->utc(),
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    $after = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)
        ->with('lines')->first();

    expect($after)->not->toBeNull()
        ->and($after->status)->toBe('computed')
        ->and($after->is_incomplete)->toBeFalse()
        ->and($after->worked_minutes)->toBe(480)
        ->and($after->rule_version_id)->toBe($payRule->id)
        ->and($after->lines)->toHaveCount(1);
});

it('recomputes BOTH the old and new date when an amend moves a punch across the date boundary', function (): void {
    $office = onWriteOffice();
    $employee = onWriteEmployee($office);
    onWritePayRule();
    $approver = User::factory()->create();

    $oldDate = '2026-08-05'; // Wednesday
    $newDate = '2026-08-06'; // Thursday
    onWritePunch($employee, $office, $oldDate, '08:00', PunchDirection::In);
    $outLog = onWritePunch($employee, $office, $oldDate, '17:00', PunchDirection::Out);

    $before = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $oldDate)->first();
    expect($before)->not->toBeNull()
        ->and($before->is_incomplete)->toBeFalse()
        ->and($before->worked_minutes)->toBe(480);

    // Amend the Out punch to a time on the NEXT office-local date — the annulled log's
    // original date ($oldDate) is left with only the unpaired In punch, and $newDate
    // gets a lone unpaired Out punch of its own.
    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Amend,
        'target_log_id' => $outLog->id,
        'direction' => PunchDirection::Out,
        'punched_at' => Carbon::parse("{$newDate} 00:10", $office->timezone)->utc(),
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    $oldAfter = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $oldDate)->first();
    $newAfter = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $newDate)->first();

    // The old date no longer counts the now-annulled Out punch: only the In punch
    // remains there, which is unpaired => incomplete, zero worked.
    expect($oldAfter)->not->toBeNull()
        ->and($oldAfter->is_incomplete)->toBeTrue()
        ->and($oldAfter->worked_minutes)->toBe(0);

    // The new date got its own fresh (also incomplete — a lone Out punch) summary via
    // RecordPunch's own trigger.
    expect($newAfter)->not->toBeNull()
        ->and($newAfter->is_incomplete)->toBeTrue()
        ->and($newAfter->worked_minutes)->toBe(0);
});

it('recomputes the annulled punch\'s own day after an approved void, back to incomplete', function (): void {
    $office = onWriteOffice();
    $employee = onWriteEmployee($office);
    onWritePayRule();
    $approver = User::factory()->create();

    $date = '2026-08-06'; // Thursday
    onWritePunch($employee, $office, $date, '08:00', PunchDirection::In);
    $outLog = onWritePunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $before = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();
    expect($before)->not->toBeNull()
        ->and($before->is_incomplete)->toBeFalse()
        ->and($before->worked_minutes)->toBe(480);

    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $outLog->id,
        'direction' => null,
        'punched_at' => null,
    ]);

    app(ApplyAttendanceAdjustment::class)->apply($request, $approver->id);

    $after = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();

    expect($after)->not->toBeNull()
        ->and($after->is_incomplete)->toBeTrue()
        ->and($after->worked_minutes)->toBe(0);
});

// cross-midnight ---------------------------------------------------------------------

/** Every day 22:00-06:00 (1320-1800 minutes, no break) — a pure night-shift office. */
function onWriteNightOffice(): Office
{
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Night']);
    foreach (Weekday::cases() as $wd) {
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id,
            'weekday' => $wd,
            'is_rest' => false,
            'start_minute' => 1320,
            'end_minute' => 1800,
            'break_minutes' => 0,
        ]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    return $office;
}

it('completes the previous day when a night shift punches out after midnight', function (): void {
    // EffectivePunches assigns a post-midnight punch to the PREVIOUS business day's shift
    // window — that is what makes a 22:00-06:00 shift one day rather than two halves. This
    // action used to compute only the punch's OWN local date, so the out-punch computed
    // 08-04 (whose window correctly excludes it) and 08-03 was never revisited by the punch
    // that completed it: permanently unpaired, worked 0, is_incomplete true. And because
    // CloseCutoff refuses to close over an incomplete day, an office running night shifts
    // could never close a cutoff at all.
    $office = onWriteNightOffice();
    $employee = onWriteEmployee($office);
    onWritePayRule();

    onWritePunch($employee, $office, '2026-08-03', '22:00', PunchDirection::In);

    $afterIn = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', '2026-08-03')->first();
    expect($afterIn->is_incomplete)->toBeTrue();

    onWritePunch($employee, $office, '2026-08-04', '06:00', PunchDirection::Out);

    $afterOut = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', '2026-08-03')->first();

    expect($afterOut->is_incomplete)->toBeFalse()
        ->and($afterOut->worked_minutes)->toBe(480);
});

it('does not resurrect a closed previous day when a punch lands after midnight', function (): void {
    // The extra date this action now computes must still respect the closed-period freeze.
    // ComputeDailySummary's own guard does the refusing; this proves the new second compute
    // is routed through it rather than around it.
    $office = onWriteNightOffice();
    $employee = onWriteEmployee($office);
    onWritePayRule();

    CutoffPeriod::create([
        'office_id' => $office->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
        'state' => 'closed',
        'closed_at' => now(),
    ]);

    onWritePunch($employee, $office, '2026-08-04', '00:30', PunchDirection::In);

    expect(DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-08-03')
        ->exists())->toBeFalse();
});
