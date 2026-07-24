<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\CreateScheduleOverride;
use App\Actions\Schedules\CreateScheduleOverrideInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CreateScheduleOverrideRequest;
use App\Http\Resources\ScheduleOverrideResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateOverrideController
{
    public function __invoke(CreateScheduleOverrideRequest $request, CreateScheduleOverride $action): JsonResponse
    {
        $employeeId = $request->string('employee_id')->toString();

        // An override targets an employee; scope is via the employee's current office —
        // not its own office_id column. The FormRequest validates employee_id as shape
        // only (uuid, no `exists:`), so a fabricated id resolves to a null office here —
        // exactly like an out-of-scope one failing OfficeScope::administers below — and
        // both land in the same 404. No enumeration oracle.
        $officeId = Employee::query()->find($employeeId)?->current_office_id;

        if ($officeId === null || ! OfficeScope::administers($request->user(), $officeId)) {
            throw new NotFoundHttpException;
        }

        $override = $action->execute(new CreateScheduleOverrideInput(
            employeeId: $employeeId,
            date: $request->string('date')->toString(),
            isRest: $request->boolean('is_rest'),
            startMinute: $request->filled('start_minute') ? $request->integer('start_minute') : null,
            endMinute: $request->filled('end_minute') ? $request->integer('end_minute') : null,
            breakMinutes: $request->filled('break_minutes') ? $request->integer('break_minutes') : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            actorId: $request->user()->id,
        ));

        return ScheduleOverrideResource::make($override)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
