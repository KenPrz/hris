<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Departments;

use App\Actions\Departments\UpdateDepartment;
use App\Actions\Departments\UpdateDepartmentInput;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateDepartmentRequest $request, Department $department, UpdateDepartment $action): JsonResponse
    {
        $validated = $request->validated();

        $updated = $action->execute(new UpdateDepartmentInput(
            departmentId: $department->id,
            officeId: (string) $validated['office_id'],
            name: (string) $validated['name'],
            code: (string) $validated['code'],
            actorId: $request->user()->id,
        ));

        return DepartmentResource::make($updated)->response();
    }
}
