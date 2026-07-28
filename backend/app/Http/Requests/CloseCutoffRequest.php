<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CloseCutoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /**
     * Shape only — deliberately NO `exists:offices,id`. That would let a fake office id
     * 400 while an out-of-scope real one 404s in the controller, reintroducing the
     * enumeration oracle the 404-not-403 rule exists to close. `period_start` is validated
     * as a date only — whether it lands on a valid semi-monthly boundary (the 1st or the
     * 16th) is CloseCutoff's job (InvalidCutoffStart, a 422), not this request's.
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'uuid'],
            'period_start' => ['required', 'date'],
        ];
    }
}
