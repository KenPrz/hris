<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Resources\EmployeeProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The employee's own personnel file. No policy call needed — "self" is the whole check, and
 * a user with no employee row has no profile to show (404, matching how /me renders a null
 * employee for a login-less sysadmin).
 */
final class ShowMyProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotFoundHttpException();
        }

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
