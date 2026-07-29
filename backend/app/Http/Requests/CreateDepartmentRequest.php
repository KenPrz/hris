<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateDepartmentRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring CreateOfficeRequest. The org tree is global config, not a
     * per-office/per-subject resource, so the 404-not-403 enumeration discipline does
     * not apply — a non-admin gets the default failedAuthorization()
     * (AuthorizationException -> 403 forbidden).
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // Shape-only (uuid), deliberately not `exists:offices,id` — this is a
            // system-admin surface, not a per-subject scoped one, so a nonexistent
            // office_id becomes a 422 from the FK constraint inside the action, never a
            // 404. Do not add 404-scoping here (see CreateOfficeRequest brief).
            'office_id' => ['required', 'uuid'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }
}
