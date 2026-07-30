<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\ReplaceEmployeeDependents;
use App\Actions\Profile\ReplaceEmployeeDependentsInput;
use App\Http\Requests\Profile\ReplaceDependentsRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class ReplaceDependentsController
{
    public function __invoke(ReplaceDependentsRequest $request, Employee $employee, ReplaceEmployeeDependents $action): JsonResponse
    {
        $action->execute(new ReplaceEmployeeDependentsInput(
            employeeId: $employee->id,
            dependents: $request->validated()['dependents'],
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
