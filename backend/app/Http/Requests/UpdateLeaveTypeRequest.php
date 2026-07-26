<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    public function rules(): array
    {
        return [
            // No office_id here — the office is fixed by the route-bound leave type, not
            // something a caller may retarget in the body.
            'name' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'is_paid' => ['required', 'boolean'],
            'requires_attachment' => ['required', 'boolean'],
            'deducts_balance' => ['required', 'boolean'],
            'is_cash_convertible' => ['required', 'boolean'],
            'max_carryover_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
