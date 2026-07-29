<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetHrAdminOfficesRequest extends FormRequest
{
    /** Sysadmin-only, mirroring ShowEmployeeRequest — a non-admin gets the default 403. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // `present`, not `required` — Laravel's `required` rule treats an empty
            // array as missing, but `office_ids: []` is a legitimate, meaningful payload
            // here (it revokes HR-Admin access entirely). `present` only demands the key
            // exist in the request, so [] passes while an omitted key still fails.
            //
            // Shape-only (uuid), deliberately not `exists:offices,id` — the controller
            // translates a dangling office id into InvalidReference itself (M8a
            // convention), never a 404.
            'office_ids' => ['present', 'array'],
            'office_ids.*' => ['uuid'],
        ];
    }
}
