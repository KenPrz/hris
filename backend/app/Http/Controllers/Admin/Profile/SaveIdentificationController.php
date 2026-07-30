<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\SaveEmployeeIdentification;
use App\Actions\Profile\SaveEmployeeIdentificationInput;
use App\Http\Requests\Profile\SaveIdentificationRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class SaveIdentificationController
{
    public function __invoke(SaveIdentificationRequest $request, Employee $employee, SaveEmployeeIdentification $action): JsonResponse
    {
        $validated = $request->validated();

        $action->execute(new SaveEmployeeIdentificationInput(
            employeeId: $employee->id,
            categoryId: (string) $validated['category_id'],
            number: (string) $validated['number'],
            issuedOn: $validated['issued_on'] ?? null,
            expiresOn: $validated['expires_on'] ?? null,
            notes: $validated['notes'] ?? null,
            scan: $request->file('scan'),
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
