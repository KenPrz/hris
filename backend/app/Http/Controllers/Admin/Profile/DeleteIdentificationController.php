<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\DeleteEmployeeIdentification;
use App\Actions\Profile\DeleteEmployeeIdentificationInput;
use App\Http\Requests\Profile\DeleteIdentificationRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Http\JsonResponse;

final class DeleteIdentificationController
{
    public function __invoke(
        DeleteIdentificationRequest $request,
        Employee $employee,
        EmployeeIdentification $identification,
        DeleteEmployeeIdentification $action,
    ): JsonResponse {
        $action->execute(new DeleteEmployeeIdentificationInput(identificationId: $identification->id));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
