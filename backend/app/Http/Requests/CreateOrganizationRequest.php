<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateOrganizationRequest extends FormRequest
{
    /**
     * Sysadmin-only, the codebase's usual idiom (CreatePayRuleRequest, CreateEmployeeRequest).
     * The org tree is global config, not a per-office/per-subject resource, so the
     * 404-not-403 enumeration discipline does not apply here — a non-admin gets the
     * default failedAuthorization() (AuthorizationException -> 403 forbidden).
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'legal_name' => ['nullable', 'string'],
            'tin' => ['nullable', 'string'],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }
}
