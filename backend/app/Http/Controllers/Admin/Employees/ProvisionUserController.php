<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Employees\ProvisionUser;
use App\Actions\Employees\ProvisionUserInput;
use App\Http\Requests\ProvisionUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProvisionUserController
{
    public function __invoke(ProvisionUserRequest $request, Employee $employee, ProvisionUser $action): JsonResponse
    {
        $user = $action->execute(new ProvisionUserInput(
            employeeId: $employee->id,
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            name: $request->string('name')->toString(),
        ));

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
