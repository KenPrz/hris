<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\CreateEmployeeInput;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Onboarding entry point. System Admin owns employee creation in M2 — there is no
 * self-serve path — so the FormRequest's authorize() is the entire authorization
 * boundary here.
 */
final class CreateEmployeeController
{
    public function __invoke(CreateEmployeeRequest $request, CreateEmployee $action): JsonResponse
    {
        $employment = $request->input('employment');

        $employee = $action->execute(new CreateEmployeeInput(
            employeeNo: $request->string('employee_no')->toString(),
            organizationId: $request->string('organization_id')->toString(),
            hiredAt: $request->string('hired_at')->toString(),
            firstEmployment: $employment === null ? null : new RecordEmploymentChangeInput(
                employeeId: '', // overwritten by CreateEmployee once the employee exists
                effectiveFrom: (string) $employment['effective_from'],
                officeId: (string) $employment['office_id'],
                departmentId: (string) $employment['department_id'],
                reportsToId: $employment['reports_to_id'] ?? null,
                employmentType: (string) $employment['employment_type'],
                isArt82Exempt: (bool) $employment['is_art82_exempt'],
                baseRateCents: (int) $employment['base_rate_cents'],
                actorId: $request->user()->id,
            ),
            actorId: $request->user()->id,
        ));

        return EmployeeResource::make($employee)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
