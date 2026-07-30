<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOfficeRequest extends FormRequest
{
    /** Sysadmin-only, mirroring CreateOfficeRequest. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // Same full-object-on-PATCH treatment as UpdateOrganizationRequest: every
            // field required/nullable as appropriate rather than `sometimes`, since the
            // action always writes every column.
            'organization_id' => ['required', 'uuid'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
            'region' => ['nullable', 'string', 'max:32'],
            'geofence_lat' => ['nullable', 'numeric'],
            'geofence_lng' => ['nullable', 'numeric'],
            'geofence_radius_m' => ['nullable', 'integer'],
            'ip_allowlist' => ['nullable', 'array'],
            'default_shift_template_id' => ['nullable', 'uuid'],
        ];
    }
}
