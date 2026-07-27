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
| pure DailyComputation calculator: does this employee have an APPROVED (final-hop, not
| merely manager_approved) full-day `leave` request whose [start_date, end_date] span
| covers this date. Domain-Eloquent-ok, same carve-out as EmployeeScope/ApprovalQueues.
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

    expect(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-03'))->toBeTrue()
        ->and(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-04'))->toBeTrue()
        ->and(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-05'))->toBeTrue();
});

it('is false for a date outside the span', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::Approved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-02'))->toBeFalse()
        ->and(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-06'))->toBeFalse();
});

it('is false for a pending leave request (not yet approved)', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::Pending, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-03'))->toBeFalse();
});

it('is false for a manager_approved leave request (only the final hop counts)', function (): void {
    $employee = Employee::factory()->create();
    leaveDayLookupRequest($employee, RequestState::ManagerApproved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-03'))->toBeFalse();
});

it('is false for another employee\'s approved leave request', function (): void {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create();
    leaveDayLookupRequest($other, RequestState::Approved, '2026-08-03', '2026-08-05');

    expect(LeaveDayLookup::isOnApprovedLeave($employee, '2026-08-03'))->toBeFalse();
});
