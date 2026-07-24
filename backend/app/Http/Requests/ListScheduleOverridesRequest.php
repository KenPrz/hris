<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListScheduleOverridesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is in the controller (404-not-403)
    }

    /**
     * Shape only — no `exists:offices,id` / `exists:employees,id` (that would 400 a
     * fabricated id while an out-of-scope one 404s, an enumeration oracle). `office` and
     * `employee` are validated as uuids so a malformed value is a clean 400, not a
     * Postgres uuid-cast 500. The controller does the scope resolution and 404s uniformly.
     */
    public function rules(): array
    {
        return [
            'office' => ['required', 'uuid'],
            'employee' => ['required', 'uuid'],
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
