<?php

declare(strict_types=1);

use App\Actions\Cutoff\CloseCutoff;
use App\Actions\Cutoff\CloseCutoffInput;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Cutoff\CutoffState;
use App\Exceptions\Domain\CutoffAlreadyClosed;
use App\Exceptions\Domain\CutoffHasUnresolvedExceptions;
use App\Exceptions\Domain\InvalidCutoffStart;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\OvertimeDetail;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function closeCutoff(string $officeId, string $periodStart, string $actorId): CutoffPeriod
{
    return (new CloseCutoff)->execute(new CloseCutoffInput($officeId, $periodStart, $actorId));
}

it('rejects an invalid cutoff start', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => closeCutoff($office->id, '2026-07-02', $actor->id))
        ->toThrow(InvalidCutoffStart::class);
});

it('closes a clean period and locks its in-period summaries', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $inPeriod = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);
    $outOfPeriod = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-20',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    $period = closeCutoff($office->id, '2026-07-01', $actor->id);

    expect($period->state)->toBe(CutoffState::Closed)
        ->and($period->closed_by)->toBe($actor->id)
        ->and($period->closed_at)->not->toBeNull()
        ->and($inPeriod->fresh()->status)->toBe('locked')
        ->and($outOfPeriod->fresh()->status)->toBe('computed');
});

it('refuses to close while an in-period day is incomplete', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->incomplete()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
    ]);

    try {
        closeCutoff($office->id, '2026-07-01', $actor->id);
        $this->fail('Expected CutoffHasUnresolvedExceptions to be thrown.');
    } catch (CutoffHasUnresolvedExceptions $e) {
        expect($e->details()['incomplete_dates'])->toBe(['2026-07-10'])
            ->and($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('cutoff_has_unresolved_exceptions');
    }

    expect($summary->fresh()->status)->toBe('computed');
});

it('refuses to close while a pending overtime request lands in the window', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    $request = Request::factory()->for($employee)->create(['type' => 'overtime', 'state' => 'pending']);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);

    try {
        closeCutoff($office->id, '2026-07-01', $actor->id);
        $this->fail('Expected CutoffHasUnresolvedExceptions to be thrown.');
    } catch (CutoffHasUnresolvedExceptions $e) {
        expect($e->details()['pending_request_ids'])->toBe([$request->id]);
    }

    expect($summary->fresh()->status)->toBe('computed');
});

it('refuses to close while a pending leave request overlaps the window', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $leaveType = LeaveType::factory()->create(['office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    $request = Request::factory()->for($employee)->create(['type' => 'leave', 'state' => 'pending']);
    LeaveDetail::query()->create([
        'request_id' => $request->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-07-02',
        'day_part' => 'full',
        'amount_minutes' => 1440,
        'minutes_per_day' => 480,
    ]);

    try {
        closeCutoff($office->id, '2026-07-01', $actor->id);
        $this->fail('Expected CutoffHasUnresolvedExceptions to be thrown.');
    } catch (CutoffHasUnresolvedExceptions $e) {
        expect($e->details()['pending_request_ids'])->toBe([$request->id]);
    }

    expect($summary->fresh()->status)->toBe('computed');
});

it('refuses to close while a pending attendance adjustment maps into the window', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    $request = Request::factory()->for($employee)->create(['type' => 'attendance_adjustment', 'state' => 'pending']);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::In,
        'punched_at' => '2026-07-10T02:00:00Z', // 2026-07-10 10:00 Asia/Manila
    ]);

    try {
        closeCutoff($office->id, '2026-07-01', $actor->id);
        $this->fail('Expected CutoffHasUnresolvedExceptions to be thrown.');
    } catch (CutoffHasUnresolvedExceptions $e) {
        expect($e->details()['pending_request_ids'])->toBe([$request->id]);
    }

    expect($summary->fresh()->status)->toBe('computed');
});

it('closes over a terminal request and over an out-of-period pending request', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    // Approved (terminal) overtime request in-window: must not block.
    $approvedRequest = Request::factory()->for($employee)->create(['type' => 'overtime', 'state' => 'approved']);
    OvertimeDetail::query()->create(['request_id' => $approvedRequest->id, 'date' => '2026-07-10', 'minutes' => 60]);

    // Pending overtime request out-of-window: must not block.
    $outOfWindowRequest = Request::factory()->for($employee)->create(['type' => 'overtime', 'state' => 'pending']);
    OvertimeDetail::query()->create(['request_id' => $outOfWindowRequest->id, 'date' => '2026-07-20', 'minutes' => 60]);

    $period = closeCutoff($office->id, '2026-07-01', $actor->id);

    expect($period->state)->toBe(CutoffState::Closed)
        ->and($summary->fresh()->status)->toBe('locked');
});

it('closes over a rejected request in-window', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $summary = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'status' => 'computed',
        'is_incomplete' => false,
    ]);

    $rejectedRequest = Request::factory()->for($employee)->create([
        'type' => 'overtime',
        'state' => 'rejected',
        'decision_note' => 'Not approved.',
    ]);
    OvertimeDetail::query()->create(['request_id' => $rejectedRequest->id, 'date' => '2026-07-10', 'minutes' => 60]);

    $period = closeCutoff($office->id, '2026-07-01', $actor->id);

    expect($period->state)->toBe(CutoffState::Closed)
        ->and($summary->fresh()->status)->toBe('locked');
});

it('409s a second close of an already-closed period', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();

    closeCutoff($office->id, '2026-07-01', $actor->id);

    expect(fn () => closeCutoff($office->id, '2026-07-01', $actor->id))
        ->toThrow(CutoffAlreadyClosed::class);
});
