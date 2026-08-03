<?php

declare(strict_types=1);

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Pay\SummaryLineKind;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

/*
| Task 9: ComputeDailySummary/DailyComputation price an approved full-day leave day as
| leave_with_pay at 100%. computeOffice/computeEmployee/seedPayRule/recordManualPunch live
| in support.php, shared with ComputeDailySummaryTest/RecomputeDayTest.
*/

function leaveWithPayRequest(Employee $employee, Office $office, RequestState $state, string $start, string $end): Request
{
    $leaveType = LeaveType::factory()->for($office, 'office')->create();

    $request = Request::factory()->for($employee)->create([
        'type' => RequestType::Leave,
        'state' => $state,
    ]);

    LeaveDetail::factory()->for($request)->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => $start,
        'end_date' => $end,
        'day_part' => 'full',
    ]);

    return $request;
}

/** The same, filed as a half day: day_part 'half', 240 minutes both per-day and in total. */
function leaveWithPayHalfDayRequest(Employee $employee, Office $office, string $date): Request
{
    $leaveType = LeaveType::factory()->for($office, 'office')->create();

    $request = Request::factory()->for($employee)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::Approved,
    ]);

    LeaveDetail::factory()->for($request)->halfDay()->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => $date,
        'end_date' => $date,
    ]);

    return $request;
}

it('pays a half-day leave for half a day, not a whole one', function (): void {
    // day_part was written at submit and read by nothing downstream, so this paid 540 (the
    // employee's full scheduled day) against a 240-minute ledger debit.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();
    $date = '2026-08-03'; // Monday, scheduled 540

    $request = leaveWithPayHalfDayRequest($employee, $office, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    $leave = $summary->lines->firstWhere(fn ($line) => $line->kind === SummaryLineKind::LeaveWithPay);

    expect($leave->minutes)->toBe(240)
        // The payroll line and the figure LeaveEffect debits are the same snapshot, so they
        // cannot drift: amount_minutes is minutes_per_day x scheduled working days.
        ->and($leave->minutes)->toBe($request->leaveDetail->minutes_per_day)
        ->and($request->leaveDetail->amount_minutes)->toBe($leave->minutes);
});

it('pays the worked half and the leave half when an employee works the other half', function (): void {
    // The likelier half-day case, and the one that disadvantaged the employee: punches
    // existed, so no leave line was emitted at all — debited 240 and paid only for the hours
    // actually clocked.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();
    $date = '2026-08-03'; // Monday, scheduled 540

    leaveWithPayHalfDayRequest($employee, $office, $date);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '12:00', PunchDirection::Out); // 240 gross, under the break threshold

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    $byKind = $summary->lines->keyBy(fn ($line) => $line->kind->value);

    expect($summary->worked_minutes)->toBe(240)
        ->and($byKind['regular_day']->minutes)->toBe(240)
        ->and($byKind['leave_with_pay']->minutes)->toBe(240);
});

it('prices a scheduled working day with an approved leave request and no punches as leave_with_pay at 100%, rule_version_id null with no pay_rule configured', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->worked_minutes)->toBe(0)
        ->and($summary->scheduled_minutes)->toBe(540)
        ->and($summary->rule_version_id)->toBeNull();

    expect($summary->lines)->toHaveCount(1);
    $line = $summary->lines->first();
    expect($line->kind)->toBe(SummaryLineKind::LeaveWithPay)
        // 480 — leave_details.minutes_per_day, the SAME snapshot LeaveEffect's ledger debit
        // is a multiple of. This used to be the employee's resolved scheduledMinutes (540),
        // a different number entirely: the office debited 480 and payroll paid 540.
        ->and($line->minutes)->toBe(480)
        ->and($line->applied_bp)->toBe(10000);

    // The office's minutes_per_leave_day (480) is shorter than this employee's scheduled day
    // (540), so an hour of the day is genuinely covered by neither work nor leave. That is
    // now visible as undertime instead of being paid for free.
    expect($summary->undertime_minutes)->toBe(60);
});

it('still leaves rule_version_id null for a leave-only day even when a pay_rule IS configured', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $rule = seedPayRule();
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->rule_version_id)->toBeNull()
        ->and($rule->id)->not->toBeNull(); // sanity: a version really was configured

    expect($summary->lines)->toHaveCount(1);
    expect($summary->lines->first()->kind)->toBe(SummaryLineKind::LeaveWithPay);
});

it('tops a worked day up with the leave it holds, never past the scheduled day', function (): void {
    // Was 'prices from punches, not leave_with_pay, on an approved-leave date that has real
    // punches'. That rule is what silently dropped the half-day case: an employee who took a
    // half-day and worked the other half had punches, so no leave line was emitted at all —
    // debited 240 minutes of balance and paid only for the hours they clocked.
    //
    // Leave now covers whatever part of the SCHEDULED day was not worked, and no more. Here
    // the employee holds a full day of leave (480) and worked 480 of a 540-minute scheduled
    // day, so leave tops up the remaining 60. Total paid is the scheduled day exactly — never
    // twice for the same minute.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    $byKind = $summary->lines->keyBy(fn ($line) => $line->kind->value);

    expect($summary->worked_minutes)->toBe(480)
        ->and($summary->lines)->toHaveCount(2)
        ->and($byKind['regular_day']->minutes)->toBe(480)
        ->and($byKind['leave_with_pay']->minutes)->toBe(60)
        ->and($byKind['regular_day']->minutes + $byKind['leave_with_pay']->minutes)
        ->toBe($summary->scheduled_minutes)
        ->and($summary->undertime_minutes)->toBe(0);
});

it('does not pay leave twice when the employee worked the whole scheduled day', function (): void {
    // Full-day leave on a day the employee worked its entire scheduled length: there is no
    // unworked remainder for leave to cover, so no leave line at all.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();
    $date = '2026-08-03'; // Monday, scheduled 540

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '18:00', PunchDirection::Out); // net 540

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    $kinds = $summary->lines->map(fn ($line) => $line->kind->value)->all();

    expect($summary->worked_minutes)->toBe(540)
        ->and($kinds)->not->toContain('leave_with_pay');
});

it('gives no leave_with_pay line for a rest day covered by an approved leave request', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $date = '2026-08-08'; // Saturday, rest day

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->is_rest_day)->toBeTrue()
        ->and($summary->lines)->toHaveCount(0);
});

it('gives no leave_with_pay line for a pending (not yet approved) leave request', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::Pending, $date, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->lines)->toHaveCount(0);
});

it('gives no leave_with_pay line for a manager_approved (hop 1 only) leave request', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::ManagerApproved, $date, $date);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->lines)->toHaveCount(0);
});

it('gives no leave_with_pay line for an approved leave request whose leave type is unpaid (is_paid=false)', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $date = '2026-08-03'; // Monday

    $leaveType = LeaveType::factory()->for($office, 'office')->create(['is_paid' => false]);

    $request = Request::factory()->for($employee)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::Approved,
    ]);

    LeaveDetail::factory()->for($request)->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => $date,
        'end_date' => $date,
        'day_part' => 'full',
    ]);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    // No punches, no leave_with_pay: an unpaid (LWOP) approved leave day is not priced as
    // leave_with_pay at 100% — it falls through to the normal unworked-day computation
    // (an ordinary working day here, so no holiday/leave line applies either).
    expect($summary->worked_minutes)->toBe(0)
        ->and($summary->lines)->toHaveCount(0);
});
