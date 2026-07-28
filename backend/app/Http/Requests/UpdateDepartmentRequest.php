<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDepartmentRequest extends FormRequest
{
    /** Sysadmin-only, mirroring CreateDepartmentRequest. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // Same full-object-on-PATCH treatment as UpdateOfficeRequest: every field
            // required rather than `sometimes`, since the action always writes every
            // column.
            'office_id' => ['required', 'uuid'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }
}
