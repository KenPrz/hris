<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordEmploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'office_id' => ['required', 'uuid', 'exists:offices,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
            'reports_to_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'employment_type' => ['required', 'string'],
            'is_art82_exempt' => ['required', 'boolean'],
            'base_rate_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
