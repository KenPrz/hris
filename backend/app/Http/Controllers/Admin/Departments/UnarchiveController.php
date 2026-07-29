<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Departments;

use App\Actions\Departments\UnarchiveDepartment;
use App\Http\Requests\DepartmentAdminRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

final class UnarchiveController
{
    public function __invoke(DepartmentAdminRequest $request, Department $department, UnarchiveDepartment $action): JsonResponse
    {
        $unarchived = $action->execute($department);

        return DepartmentResource::make($unarchived)->response();
    }
}
