<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationRequest extends FormRequest
{
    /** Sysadmin-only, mirroring CreateOrganizationRequest. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // Same full-object-on-PATCH treatment as UpdateLeaveTypeRequest: every field
            // required/nullable as appropriate rather than `sometimes`, since the action
            // always writes all four columns.
            'name' => ['required', 'string'],
            'legal_name' => ['nullable', 'string'],
            'tin' => ['nullable', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }
}
