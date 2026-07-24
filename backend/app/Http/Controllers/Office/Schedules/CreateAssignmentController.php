<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\CreateScheduleAssignment;
use App\Actions\Schedules\CreateScheduleAssignmentInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CreateScheduleAssignmentRequest;
use App\Http\Resources\ScheduleAssignmentResource;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateAssignmentController
{
    public function __invoke(CreateScheduleAssignmentRequest $request, CreateScheduleAssignment $action): JsonResponse
    {
        $employeeId = $request->input('employee_id');
        $departmentId = $request->input('department_id');

        // Unlike a shift template (scoped by its own office_id), an assignment is
        // scoped by the TARGET's office: the employee's current office, or the
        // department's office. The FormRequest validates these as shape only (uuid, no
        // `exists:`), so a fabricated target id resolves to a null office here — exactly
        // like an out-of-scope one failing OfficeScope::administered below — and both
        // land in the same 404. No enumeration oracle.
        $officeId = $employeeId !== null
            ? Employee::query()->find($employeeId)?->current_office_id
            : Department::query()->find($departmentId)?->office_id;

        $office = ($officeId !== null ? OfficeScope::administered($request->user(), $officeId) : null)
            ?? throw new NotFoundHttpException;

        // The template must belong to the same office as the target — a template from
        // another office cannot be assigned. Resolved scoped to $office so a foreign or
        // fabricated template id 404s identically too.
        $templateId = $request->string('shift_template_id')->toString();
        $template = $office->shiftTemplates()->find($templateId) ?? throw new NotFoundHttpException;

        $assignment = $action->execute(new CreateScheduleAssignmentInput(
            shiftTemplateId: $template->id,
            employeeId: $employeeId,
            departmentId: $departmentId,
            effectiveFrom: $request->string('effective_from')->toString(),
            actorId: $request->user()->id,
        ));

        return ScheduleAssignmentResource::make($assignment)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
