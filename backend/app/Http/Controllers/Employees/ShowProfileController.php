<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Resources\EmployeeProfileSummaryResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The manager-facing read: contact plus assignment, nothing else.
 *
 * The gate call below is also what satisfies the arch rule "every Employees controller
 * references an authorization boundary" (tests/Arch/ConventionsTest.php) — a file in this
 * directory with neither an EmployeeScope reference nor a gate call fails CI.
 */
final class ShowProfileController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        if ($request->user()->cannot('viewRedactedProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $employee->load('profile');

        return EmployeeProfileSummaryResource::make($employee)->response();
    }
}
