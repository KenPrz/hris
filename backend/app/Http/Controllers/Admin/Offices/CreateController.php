<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Offices;

use App\Actions\Offices\CreateOffice;
use App\Actions\Offices\CreateOfficeInput;
use App\Http\Requests\CreateOfficeRequest;
use App\Http\Resources\OfficeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreateOfficeRequest $request, CreateOffice $action): JsonResponse
    {
        $validated = $request->validated();

        $office = $action->execute(new CreateOfficeInput(
            organizationId: (string) $validated['organization_id'],
            name: (string) $validated['name'],
            code: (string) $validated['code'],
            timezone: (string) $validated['timezone'],
            region: $validated['region'] ?? null,
            geofenceLat: $validated['geofence_lat'] ?? null,
            geofenceLng: $validated['geofence_lng'] ?? null,
            geofenceRadiusM: $validated['geofence_radius_m'] ?? null,
            ipAllowlist: $validated['ip_allowlist'] ?? null,
            defaultShiftTemplateId: $validated['default_shift_template_id'] ?? null,
            actorId: $request->user()->id,
        ));

        return OfficeResource::make($office)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
