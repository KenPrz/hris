<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Departments;

use App\Actions\Departments\ArchiveDepartment;
use App\Http\Requests\DepartmentAdminRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;

final class ArchiveController
{
    public function __invoke(DepartmentAdminRequest $request, Department $department, ArchiveDepartment $action): JsonResponse
    {
        $archived = $action->execute($department);

        return DepartmentResource::make($archived)->response();
    }
}
