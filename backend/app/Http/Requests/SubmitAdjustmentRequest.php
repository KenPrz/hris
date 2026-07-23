<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated user; the "is an employee" check is in the controller
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['add', 'void', 'amend'])],
            'note' => ['required', 'string'],
            'target_log_id' => ['required_if:operation,void,amend', 'uuid', 'exists:attendance_logs,id'],
            'direction' => ['required_if:operation,add,amend', Rule::in(['in', 'out'])],
            'punched_at' => ['required_if:operation,add,amend', 'date'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
