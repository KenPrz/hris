<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListScheduleAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is in the controller (404-not-403)
    }

    /**
     * Shape only — no `exists:` on any id (that would 400 a fabricated one while an
     * out-of-scope one 404s, an enumeration oracle). Each is validated as a uuid so a
     * malformed value is a clean 400, not a Postgres uuid-cast 500. The controller does
     * the scope resolution and 404s uniformly.
     */
    public function rules(): array
    {
        return [
            'office' => ['required', 'uuid'],
            'employee' => ['nullable', 'uuid'],
            'department' => ['nullable', 'uuid'],
        ];
    }
}
