<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Offices;

use App\Actions\Offices\UpdateOffice;
use App\Actions\Offices\UpdateOfficeInput;
use App\Http\Requests\UpdateOfficeRequest;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateOfficeRequest $request, Office $office, UpdateOffice $action): JsonResponse
    {
        $validated = $request->validated();

        $updated = $action->execute(new UpdateOfficeInput(
            officeId: $office->id,
            organizationId: (string) $validated['organization_id'],
            name: (string) $validated['name'],
            code: (string) $validated['code'],
            timezone: (string) $validated['timezone'],
            geofenceLat: $validated['geofence_lat'] ?? null,
            geofenceLng: $validated['geofence_lng'] ?? null,
            geofenceRadiusM: $validated['geofence_radius_m'] ?? null,
            ipAllowlist: $validated['ip_allowlist'] ?? null,
            defaultShiftTemplateId: $validated['default_shift_template_id'] ?? null,
            actorId: $request->user()->id,
        ));

        return OfficeResource::make($updated)->response();
    }
}
