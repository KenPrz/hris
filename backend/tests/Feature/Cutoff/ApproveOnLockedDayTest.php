<?php

declare(strict_types=1);

use App\Actions\Requests\ApproveRequest;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Exceptions\Domain\CutoffLocked;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\CutoffPeriod;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\OvertimeDetail;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
| Task 6: ApproveRequest refuses an approval whose effect would change a day that falls in
| a CLOSED cutoff period — CutoffGuard::assertOpen throws CutoffLocked (422) on the final
| hop, before the effect fires, so the request stays in its prior state and NOTHING lands
| (no new punch, no ledger debit, no summary change). Each type gets a refusal case (period
| closed) and a control (period open, approves normally). These are single-process tests
| that exercise the guard's PLACEMENT; the two-connection lock proof lives in
| CloseVsApproveConcurrencyTest.
|
| Bus::fake() throughout: a successful control approval enqueues a recompute via
| DB::afterCommit (Overtime/Leave effects). We only care that the approval lands, not that
| the recompute runs, so we keep the sync queue from executing a real ComputeDailySummary.
*/

beforeEach(function (): void {
    Bus::fake();
});

/**
 * A manager (user+employee) and their direct report (user+employee) in one office, plus an
 * HR admin over that office for the leave final hop.
 *
 * @return array{0: Office, 1: User, 2: Employee, 3: User}
 */
function lockedDayActors(): array
{
    $office = Office::factory()->create(['ip_allowlist' => null, 'timezone' => 'Asia/Manila']);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);

    $reportUser = User::factory()->create();
    $report = Employee::factory()->for($reportUser)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);

    return [$office, $managerUser, $report, $hrUser];
}

// --- Attendance adjustment (single-hop, manager decides) -------------------------------

it('refuses to approve an attendance adjustment whose punch falls in a closed period', function (): void {
    [$office, $managerUser, $report] = lockedDayActors();

    // 2026-07-10 02:00Z is 2026-07-10 10:00 Asia/Manila — inside 2026-07-01..2026-07-15.
    CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15',
    ]);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::AttendanceAdjustment, 'state' => RequestState::Pending,
    ]);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add, 'target_log_id' => null,
        'direction' => PunchDirection::In, 'punched_at' => '2026-07-10T02:00:00Z',
    ]);

    try {
        app(ApproveRequest::class)->execute($request->fresh(), $managerUser);
        $this->fail('Expected CutoffLocked to be thrown.');
    } catch (CutoffLocked $e) {
        expect($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('cutoff_locked')
            ->and($e->details()['date'])->toBe('2026-07-10');
    }

    expect($request->fresh()->state)->toBe(RequestState::Pending)
        ->and(AttendanceLog::where('employee_id', $report->id)->count())->toBe(0);
});

it('approves an attendance adjustment when the period is open (control)', function (): void {
    [$office, $managerUser, $report] = lockedDayActors();

    CutoffPeriod::factory()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15', 'state' => 'open',
    ]);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::AttendanceAdjustment, 'state' => RequestState::Pending,
    ]);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add, 'target_log_id' => null,
        'direction' => PunchDirection::In, 'punched_at' => '2026-07-10T02:00:00Z',
    ]);

    app(ApproveRequest::class)->execute($request->fresh(), $managerUser);

    expect($request->fresh()->state)->toBe(RequestState::Approved)
        ->and(AttendanceLog::where('employee_id', $report->id)->count())->toBe(1);
});

// --- Overtime (single-hop, manager decides) --------------------------------------------

it('refuses to approve an overtime request whose date falls in a closed period', function (): void {
    [$office, $managerUser, $report] = lockedDayActors();

    CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15',
    ]);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Overtime, 'state' => RequestState::Pending,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);

    try {
        app(ApproveRequest::class)->execute($request->fresh(), $managerUser);
        $this->fail('Expected CutoffLocked to be thrown.');
    } catch (CutoffLocked $e) {
        expect($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('cutoff_locked')
            ->and($e->details()['date'])->toBe('2026-07-10');
    }

    expect($request->fresh()->state)->toBe(RequestState::Pending);
    Bus::assertNothingBatched();
});

it('approves an overtime request when the period is open (control)', function (): void {
    [$office, $managerUser, $report] = lockedDayActors();

    CutoffPeriod::factory()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15', 'state' => 'open',
    ]);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Overtime, 'state' => RequestState::Pending,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);

    app(ApproveRequest::class)->execute($request->fresh(), $managerUser);

    expect($request->fresh()->state)->toBe(RequestState::Approved);
});

// --- Leave (two-hop; the final hop is HR, where the guard runs) -------------------------

it('refuses to approve a leave request on its final hop when a leave date falls in a closed period', function (): void {
    [$office, $managerUser, $report, $hrUser] = lockedDayActors();

    CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15',
    ]);

    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);
    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id, 'entry_type' => 'credit', 'minutes' => 2880,
    ]);

    // Already at the final hop (manager approved), so this HR decision is the effect hop.
    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave, 'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id, 'manager_decided_at' => now(),
    ]);
    LeaveDetail::factory()->for($request)->create([
        'leave_type_id' => $leaveType->id, 'start_date' => '2026-07-10', 'end_date' => '2026-07-11',
        'day_part' => 'full', 'amount_minutes' => 960,
    ]);

    try {
        app(ApproveRequest::class)->execute($request->fresh(), $hrUser);
        $this->fail('Expected CutoffLocked to be thrown.');
    } catch (CutoffLocked $e) {
        expect($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('cutoff_locked')
            ->and($e->details()['date'])->toBe('2026-07-10');
    }

    expect($request->fresh()->state)->toBe(RequestState::ManagerApproved)
        ->and(LeaveLedger::where('employee_id', $report->id)->where('entry_type', 'debit')->count())->toBe(0);
});

it('approves a leave request on its final hop when the period is open (control)', function (): void {
    [$office, $managerUser, $report, $hrUser] = lockedDayActors();

    CutoffPeriod::factory()->create([
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15', 'state' => 'open',
    ]);

    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);
    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id, 'entry_type' => 'credit', 'minutes' => 2880,
    ]);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave, 'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id, 'manager_decided_at' => now(),
    ]);
    LeaveDetail::factory()->for($request)->create([
        'leave_type_id' => $leaveType->id, 'start_date' => '2026-07-10', 'end_date' => '2026-07-11',
        'day_part' => 'full', 'amount_minutes' => 960,
    ]);

    app(ApproveRequest::class)->execute($request->fresh(), $hrUser);

    expect($request->fresh()->state)->toBe(RequestState::Approved)
        ->and(LeaveLedger::where('employee_id', $report->id)->where('entry_type', 'debit')->count())->toBe(1);
});
