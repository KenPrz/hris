<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Organizations;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\CreateOrganizationInput;
use App\Http\Requests\CreateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreateOrganizationRequest $request, CreateOrganization $action): JsonResponse
    {
        $validated = $request->validated();

        $organization = $action->execute(new CreateOrganizationInput(
            name: (string) $validated['name'],
            legalName: $validated['legal_name'] ?? null,
            tin: $validated['tin'] ?? null,
            timezone: (string) $validated['timezone'],
            actorId: $request->user()->id,
        ));

        return OrganizationResource::make($organization)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
