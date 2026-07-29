<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Access\SetHrAdminOffices;
use App\Actions\Access\SetHrAdminOfficesInput;
use App\Exceptions\Domain\EmployeeHasNoLogin;
use App\Exceptions\Domain\InvalidReference;
use App\Http\Requests\SetHrAdminOfficesRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Http\JsonResponse;

final class SetHrAdminOfficesController
{
    public function __invoke(SetHrAdminOfficesRequest $request, Employee $employee, SetHrAdminOffices $action): JsonResponse
    {
        $userId = $employee->user_id;

        if ($userId === null) {
            throw new EmployeeHasNoLogin($employee->id);
        }

        /** @var array<int, string> $officeIds */
        $officeIds = $request->validated('office_ids');

        foreach ($officeIds as $officeId) {
            if (! Office::query()->whereKey($officeId)->exists()) {
                throw new InvalidReference('office', $officeId);
            }
        }

        $action->execute(new SetHrAdminOfficesInput(
            userId: $userId,
            officeIds: $officeIds,
            actorId: $request->user()->id,
        ));

        return EmployeeDetailResource::make($employee->fresh())
            ->response();
    }
}
