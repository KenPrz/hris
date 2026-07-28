<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEmployeeRequest extends FormRequest
{
    /** Sysadmin-only, mirroring CreateEmployeeRequest — no self-serve name edits in M8b. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            // No employee_no field here — employee_no is immutable, set once at
            // creation by CreateEmployee and never editable through this endpoint.
            'first_name' => ['required', 'string'],
            'middle_name' => ['nullable', 'string'],
            'last_name' => ['required', 'string'],
            'name_suffix' => ['nullable', 'string'],
        ];
    }
}
