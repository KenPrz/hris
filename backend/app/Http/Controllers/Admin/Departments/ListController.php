<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Departments;

use App\Http\Requests\ListDepartmentsRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListDepartmentsRequest $request): AnonymousResourceCollection
    {
        $includeArchived = $request->boolean('include_archived');
        $office = $request->string('office')->toString();

        $departments = Department::query()
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($office !== '', fn ($q) => $q->where('office_id', $office))
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }
}
