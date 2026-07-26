<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\GrantLeave;
use App\Actions\Leave\GrantLeaveInput;
use App\Domain\Leave\LeaveUnit;
use App\Domain\Scope\OfficeScope;
use App\Exceptions\Domain\LeaveTypeNotGrantable;
use App\Http\Requests\GrantLeaveRequest;
use App\Http\Resources\LeaveLedgerResource;
use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HR manually crediting an employee's leave balance. Grants are HR-over-the-office only
 * — scoped via OfficeScope::administers against the employee's current office, never
 * EmployeeScope, which would also let a manager grant to their own direct reports. Every
 * lookup here (employee, office, leave type) 404s uniformly on failure: an out-of-scope
 * subject and a nonexistent one must be indistinguishable to the caller (404-not-403).
 */
final class GrantController
{
    public function __invoke(GrantLeaveRequest $request, GrantLeave $action): JsonResponse
    {
        $employee = Employee::find($request->string('employee_id')->toString())
            ?? throw new NotFoundHttpException;

        if (! OfficeScope::administers($request->user(), (string) $employee->current_office_id)) {
            throw new NotFoundHttpException;
        }

        $office = $employee->currentOffice;

        $leaveType = LeaveType::query()
            ->where('office_id', $employee->current_office_id)
            ->find($request->string('leave_type_id')->toString())
            ?? throw new NotFoundHttpException;

        if (! $leaveType->deducts_balance) {
            throw new LeaveTypeNotGrantable($leaveType->id);
        }

        $minutes = LeaveUnit::toMinutes(
            $request->integer('amount'),
            $request->string('unit')->toString(),
            $office->minutes_per_leave_day,
        );

        $entry = $action->execute(new GrantLeaveInput(
            employeeId: $employee->id,
            leaveTypeId: $leaveType->id,
            minutes: $minutes,
            reason: $request->string('reason')->toString(),
            actorId: $request->user()->id,
        ));

        return LeaveLedgerResource::make($entry)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
