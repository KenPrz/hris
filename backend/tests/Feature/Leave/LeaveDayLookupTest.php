<?php

declare(strict_types=1);

use App\Domain\Leave\LeaveDayLookup;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Task 9: LeaveDayLookup — the one fact ComputeDailySummary resolves and hands to the
| pure DailyComputation calculator: how many PAID-leave minutes this employee holds for
| this date, across every APPROVED (final-hop, not merely manager_approved) `leave`
| request whose [start_date, end_date] span covers it. Domain-Eloquent-ok, same carve-out
| as EmployeeScope/ApprovalQueues.
|
| Minutes rather than a boolean since M10c: as a boolean, leave_details.day_part was
| written at submit and read by nothing, so a half-day leave was priced as a full day.
*/

function leaveDayLookupRequest(Employee $employee, RequestState $state, string $start, string $end): Request
{
    $office = $employee->current_office_id !== null
        ? Office::find($employee->current_office_id)
        : Office::factory()->create();

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

it('is true for a date inside an approved leave request\'s span', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::Approved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-03'))->toBe(480)
        ->and(LeaveDayLookup::paidMinutesFor($employee, '2026-08-04'))->toBe(480)
        ->and(LeaveDayLookup::paidMinutesFor($employee, '2026-08-05'))->toBe(480);
});

it('is false for a date outside the span', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::Approved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-02'))->toBe(0)
        ->and(LeaveDayLookup::paidMinutesFor($employee, '2026-08-06'))->toBe(0);
});

it('is false for a pending leave request (not yet approved)', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::Pending, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-03'))->toBe(0);
});

it('is false for a manager_approved leave request (only the final hop counts)', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::ManagerApproved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-03'))->toBe(0);
});

it('is false for another employee\'s approved leave request', function (): void {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create();
    leaveDayLookupRequest($other, RequestState::Approved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-03'))->toBe(0);
});

it('is false for an approved leave request whose leave type is unpaid (is_paid=false)', function (): void {
    $employee = Employee::factory()->create();
    $office = $employee->current_office_id !== null
        ? Office::find($employee->current_office_id)
        : Office::factory()->create();

    $leaveType = LeaveType::factory()->for($office, 'office')->create(['is_paid' => false]);

    $request = Request::factory()->for($employee)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::Approved,
    ]);

    LeaveDetail::factory()->for($request)->create([
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
    ]);

    expect(LeaveDayLookup::paidMinutesFor($employee, '2026-08-03'))->toBe(0)
        ->and(LeaveDayLookup::paidMinutesFor($employee, '2026-08-04'))->toBe(0)
        ->and(LeaveDayLookup::paidMinutesFor($employee, '2026-08-05'))->toBe(0);
});
