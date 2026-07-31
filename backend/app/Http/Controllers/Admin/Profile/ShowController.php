<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 404, never 403, for an out-of-scope employee — the id is in the URL, and a
 * 403-for-real/404-for-nonexistent split would let any authenticated user enumerate which
 * employee ids exist. Same discipline as ProvisionUserRequest. See docs/05-rbac.md.
 */
final class ShowController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        if ($request->user()->cannot('viewFullProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
