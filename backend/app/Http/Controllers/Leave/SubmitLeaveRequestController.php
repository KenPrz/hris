<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\SubmitLeaveRequest;
use App\Actions\Leave\SubmitLeaveRequestInput;
use App\Domain\Leave\LeaveDays;
use App\Domain\Leave\LeaveUnit;
use App\Exceptions\Domain\LeaveAttachmentRequired;
use App\Exceptions\Domain\LeaveRequestHasNoWorkingDays;
use App\Exceptions\Domain\LeaveTypeInactive;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\SubmitLeaveRequestRequest;
use App\Http\Resources\RequestResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An employee filing their own leave request. Any employee may file — deliberately not
 * admin-gated, mirroring the attendance-adjustment submit route. The leave type is
 * resolved scoped to the EMPLOYEE's own current office (never a caller-supplied office),
 * so a foreign-office type 404s exactly like a nonexistent one (404-not-403).
 *
 * The amount debited is never client-supplied: it is derived here from the scheduled
 * working days the [start_date, end_date] range actually spans (LeaveDays), converted to
 * minutes via the same office day-length the balance/grant paths use (LeaveUnit) — a
 * client cannot inflate or shrink its own debit by sending a different number.
 */
final class SubmitLeaveRequestController
{
    public function __invoke(SubmitLeaveRequestRequest $request, SubmitLeaveRequest $action): JsonResponse
    {
        $employee = $request->user()->employee ?? throw new NotAnEmployee;

        $office = $employee->currentOffice;

        $leaveType = LeaveType::query()
            ->where('office_id', $employee->current_office_id)
            ->find($request->string('leave_type_id')->toString())
            ?? throw new NotFoundHttpException;

        if (! $leaveType->is_active) {
            throw new LeaveTypeInactive($leaveType->id);
        }

        if ($leaveType->requires_attachment && $request->file('attachment') === null) {
            throw new LeaveAttachmentRequired($leaveType->id);
        }

        $startDate = $request->string('start_date')->toString();
        $endDate = $request->string('end_date')->toString();
        $dayPart = $request->string('day_part')->toString();

        $days = LeaveDays::scheduledWorkingDays($employee, $startDate, $endDate);

        if (count($days) === 0) {
            throw new LeaveRequestHasNoWorkingDays;
        }

        $perDay = LeaveUnit::toMinutes(
            1,
            $dayPart === 'half' ? 'half_shift' : 'day',
            $office->minutes_per_leave_day,
        );

        $amount = count($days) * $perDay;

        $result = $action->execute(new SubmitLeaveRequestInput(
            employeeId: $employee->id,
            leaveTypeId: $leaveType->id,
            startDate: $startDate,
            endDate: $endDate,
            dayPart: $dayPart,
            amountMinutes: $amount,
            minutesPerDay: $perDay,
            note: $request->string('note')->toString(),
            attachment: $request->file('attachment'),
        ));

        return RequestResource::make($result)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
