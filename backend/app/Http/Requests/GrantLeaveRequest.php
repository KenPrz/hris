<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GrantLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    public function rules(): array
    {
        return [
            // Shape only — deliberately NO `exists:...`. That would let a fake id 400
            // while an out-of-scope real one 404s in the controller, reintroducing the
            // enumeration oracle the 404-not-403 rule exists to close.
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'unit' => ['required', Rule::in(['day', 'half_shift', 'hour', 'minute'])],
            'reason' => ['required', 'string'],
        ];
    }
}
