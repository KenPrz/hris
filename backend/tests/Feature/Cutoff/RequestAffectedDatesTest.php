<?php

declare(strict_types=1);

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Cutoff\RequestAffectedDates;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps an overtime request to its single date', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-10']);
});

it('maps a leave request to every date in its range', function (): void {
    $office = Office::factory()->create();
    $type = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->create(['type' => 'leave']);
    LeaveDetail::query()->create(['request_id' => $request->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-07-14', 'end_date' => '2026-07-16', 'day_part' => 'full', 'amount_minutes' => 1440, 'minutes_per_day' => 480]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-14', '2026-07-15', '2026-07-16']);
});

it('maps an add adjustment to the office-timezone date of its punch', function (): void {
    // Asia/Manila is UTC+8: 2026-07-10T20:30:00Z is 2026-07-11 04:30 local.
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create(['type' => 'attendance_adjustment']);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add, 'target_log_id' => null,
        'direction' => PunchDirection::In, 'punched_at' => '2026-07-10T20:30:00Z',
    ]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-11']);
});

it('maps a void adjustment to the office-timezone date of the target log punch', function (): void {
    // Same cross-midnight instant as the add case, but sourced from the target log instead
    // of the detail's own punched_at — proves the void/amend branch reads targetLog->punched_at.
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $targetLog = AttendanceLog::factory()->for($employee)->for($office)->create([
        'punched_at' => '2026-07-10T20:30:00Z',
    ]);
    $request = Request::factory()->for($employee)->create(['type' => 'attendance_adjustment']);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void, 'target_log_id' => $targetLog->id,
        'direction' => null, 'punched_at' => null,
    ]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-11']);
});
