<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Employees\UpdateEmployee;
use App\Actions\Employees\UpdateEmployeeInput;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class UpdateEmployeeController
{
    public function __invoke(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $action): JsonResponse
    {
        $validated = $request->validated();

        $updated = $action->execute(new UpdateEmployeeInput(
            employeeId: $employee->id,
            firstName: (string) $validated['first_name'],
            middleName: $validated['middle_name'] ?? null,
            lastName: (string) $validated['last_name'],
            nameSuffix: $validated['name_suffix'] ?? null,
            actorId: $request->user()->id,
        ));

        return EmployeeResource::make($updated)->response();
    }
}
