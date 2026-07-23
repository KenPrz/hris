<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowEmployeeController
{
    public function __invoke(Request $request, Employee $employee): EmployeeResource
    {
        // 404, not 403: "this exists but isn't yours" leaks the org chart, and for salary
        // records the leak is the disclosure. A denied view is indistinguishable from a
        // nonexistent id.
        if ($request->user()->cannot('view', $employee)) {
            throw new NotFoundHttpException();
        }

        return new EmployeeResource($employee);
    }
}
