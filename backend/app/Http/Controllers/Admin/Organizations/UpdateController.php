<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Organizations;

use App\Actions\Organizations\UpdateOrganization;
use App\Actions\Organizations\UpdateOrganizationInput;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganization $action): JsonResponse
    {
        $validated = $request->validated();

        $updated = $action->execute(new UpdateOrganizationInput(
            organizationId: $organization->id,
            name: (string) $validated['name'],
            legalName: $validated['legal_name'] ?? null,
            tin: $validated['tin'] ?? null,
            timezone: (string) $validated['timezone'],
            actorId: $request->user()->id,
        ));

        return OrganizationResource::make($updated)->response();
    }
}
