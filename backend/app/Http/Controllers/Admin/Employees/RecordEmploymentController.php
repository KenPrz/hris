<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Http\Requests\RecordEmploymentRequest;
use App\Http\Resources\EmploymentRecordResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RecordEmploymentController
{
    public function __invoke(
        RecordEmploymentRequest $request,
        Employee $employee,
        RecordEmploymentChange $action,
    ): JsonResponse {
        $record = $action->execute(new RecordEmploymentChangeInput(
            employeeId: $employee->id,
            effectiveFrom: $request->string('effective_from')->toString(),
            officeId: $request->string('office_id')->toString(),
            departmentId: $request->string('department_id')->toString(),
            reportsToId: $request->input('reports_to_id'),
            employmentType: $request->string('employment_type')->toString(),
            isArt82Exempt: $request->boolean('is_art82_exempt'),
            baseRateCents: (int) $request->input('base_rate_cents'),
            actorId: $request->user()->id,
        ));

        return EmploymentRecordResource::make($record)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
