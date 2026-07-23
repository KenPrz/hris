<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManualPunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The scope check (404-not-403 for an out-of-scope subject) belongs in the
        // controller against EmployeeScope, not here.
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'punched_at' => ['required', 'date'],
        ];
    }
}
