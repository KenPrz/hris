<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Http\Requests\ListEmployeesRequest;
use App\Http\Resources\EmployeeListResource;
use App\Models\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListEmployeesRequest $request): AnonymousResourceCollection
    {
        $office = $request->string('office')->toString();

        $employees = Employee::query()
            ->when($office !== '', fn ($q) => $q->where('current_office_id', $office))
            ->orderBy('employee_no')
            ->get();

        return EmployeeListResource::collection($employees);
    }
}
