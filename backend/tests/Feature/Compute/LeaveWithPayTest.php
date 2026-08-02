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
        ->and($line->minutes)->toBe(540)
        ->and($line->applied_bp)->toBe(10000);
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

it('prices from punches, not leave_with_pay, on an approved-leave date that has real punches', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();
    $date = '2026-08-03'; // Monday

    leaveWithPayRequest($employee, $office, RequestState::Approved, $date, $date);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->worked_minutes)->toBe(480)
        ->and($summary->lines)->toHaveCount(1);
    expect($summary->lines->first()->kind)->toBe(SummaryLineKind::RegularDay);
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
