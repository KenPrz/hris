<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated employee; the office-scope 404 is the controller's job
    }

    public function rules(): array
    {
        return [
            // Shape only — deliberately NO `exists:leave_types,id`. That would let a fake
            // id 400 while an out-of-scope real one (a different office's type) 404s in
            // the controller, reintroducing the enumeration oracle the 404-not-403 rule
            // exists to close.
            'leave_type_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'day_part' => ['required', Rule::in(['full', 'half'])],
            'note' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
