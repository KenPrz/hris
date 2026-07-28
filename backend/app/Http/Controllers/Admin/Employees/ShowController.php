<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Http\Requests\ShowEmployeeRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Models\Employee;

final class ShowController
{
    public function __invoke(ShowEmployeeRequest $request, Employee $employee): EmployeeDetailResource
    {
        return EmployeeDetailResource::make($employee);
    }
}
