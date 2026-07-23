<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Domain\Scope\EmployeeScope;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListEmployeesController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        // The index resolves scope through the same service the policy uses. No employee
        // outside the actor's scope is ever loaded, so there is nothing to 404 on.
        $employees = EmployeeScope::visibleTo($request->user())->orderBy('employee_no')->get();

        return EmployeeResource::collection($employees);
    }
}
