<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\UpsertEmployeeProfile;
use App\Actions\Profile\UpsertEmployeeProfileInput;
use App\Http\Requests\Profile\UpsertProfileRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class UpsertProfileController
{
    public function __invoke(UpsertProfileRequest $request, Employee $employee, UpsertEmployeeProfile $action): JsonResponse
    {
        $validated = $request->validated();

        $action->execute(new UpsertEmployeeProfileInput(
            employeeId: $employee->id,
            salutation: $validated['salutation'] ?? null,
            nickname: $validated['nickname'] ?? null,
            homeAddress: $validated['home_address'] ?? null,
            personalEmail: $validated['personal_email'] ?? null,
            phone: $validated['phone'] ?? null,
            fax: $validated['fax'] ?? null,
            mobile: $validated['mobile'] ?? null,
            emergencyContact: $validated['emergency_contact'] ?? null,
            gender: $validated['gender'] ?? null,
            birthDate: $validated['birth_date'] ?? null,
            birthplace: $validated['birthplace'] ?? null,
            maritalStatus: $validated['marital_status'] ?? null,
            citizenship: $validated['citizenship'] ?? null,
            religion: $validated['religion'] ?? null,
            bloodType: $validated['blood_type'] ?? null,
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
