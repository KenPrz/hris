<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolvedScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the employee-scope check is in the controller (404-not-403)
    }

    /**
     * Shape only — no `exists:employees,id` (that would 400 a fabricated id while an
     * out-of-scope one 404s, an enumeration oracle). `employee` is validated as a uuid so
     * a malformed value is a clean 400, not a Postgres uuid-cast 500.
     */
    public function rules(): array
    {
        return [
            'employee' => ['required', 'uuid'],
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
