<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // There is no self-serve employee creation in M2 — System Admin owns onboarding.
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['required', 'string'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'hired_at' => ['required', 'date'],

            // Optional first employment block — when present, CreateEmployee records it
            // through RecordEmploymentChange so the cache is populated on day one.
            'employment' => ['sometimes', 'array'],
            'employment.effective_from' => ['required_with:employment', 'date'],
            'employment.office_id' => ['required_with:employment', 'uuid', 'exists:offices,id'],
            'employment.department_id' => ['required_with:employment', 'uuid', 'exists:departments,id'],
            'employment.reports_to_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'employment.employment_type' => ['required_with:employment', 'string'],
            'employment.is_art82_exempt' => ['required_with:employment', 'boolean'],
            'employment.base_rate_cents' => ['required_with:employment', 'integer', 'min:0'],
        ];
    }
}
