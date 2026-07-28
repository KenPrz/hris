<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListActivityRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring ListOfficesRequest / ListOrganizationsRequest. The
     * activity log spans every subject type across the whole company — nothing to
     * scope-check against a single office or department — so a non-admin gets the
     * default failedAuthorization() (403 forbidden), never the 404-not-403 treatment
     * used for per-office/per-subject reads.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'log_name' => ['nullable', 'string'],
            'event' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string'],
            'causer_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer'],
        ];
    }
}
