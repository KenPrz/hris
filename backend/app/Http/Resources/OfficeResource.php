<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Office */
final class OfficeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            'timezone' => $this->timezone,
            'geofence_lat' => $this->geofence_lat,
            'geofence_lng' => $this->geofence_lng,
            'geofence_radius_m' => $this->geofence_radius_m,
            'ip_allowlist' => $this->ip_allowlist,
            'default_shift_template_id' => $this->default_shift_template_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
