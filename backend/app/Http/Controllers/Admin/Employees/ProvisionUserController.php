<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Employees;

use App\Actions\Employees\ProvisionUser;
use App\Actions\Employees\ProvisionUserInput;
use App\Http\Requests\ProvisionUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ProvisionUserController
{
    public function __invoke(ProvisionUserRequest $request, Employee $employee, ProvisionUser $action): JsonResponse
    {
        $email = $request->string('email')->toString();

        $user = $action->execute(new ProvisionUserInput(
            employeeId: $employee->id,
            email: $email,
            password: $request->string('password')->toString(),
            // No display name is collected up front for a new hire's login — fall back
            // to the email's local part rather than force a value the caller doesn't have.
            name: $request->filled('name')
                ? $request->string('name')->toString()
                : Str::headline(Str::before($email, '@')),
        ));

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
