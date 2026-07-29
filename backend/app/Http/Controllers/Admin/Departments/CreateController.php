<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Departments;

use App\Actions\Departments\CreateDepartment;
use App\Actions\Departments\CreateDepartmentInput;
use App\Http\Requests\CreateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreateDepartmentRequest $request, CreateDepartment $action): JsonResponse
    {
        $validated = $request->validated();

        $department = $action->execute(new CreateDepartmentInput(
            officeId: (string) $validated['office_id'],
            name: (string) $validated['name'],
            code: (string) $validated['code'],
            actorId: $request->user()->id,
        ));

        return DepartmentResource::make($department)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
