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
            // Scoped to the submitter's OWN logs, not `exists:attendance_logs,id`. An
            // unscoped rule (a) lets a caller probe whether any log id exists company-wide,
            // and (b) accepts a void/amend of someone else's punch that only fails at
            // approval time — clogging a manager's queue with requests guaranteed to 422.
            // A foreign log and a nonexistent one now fail validation identically here.
            'target_log_id' => [
                'required_if:operation,void,amend',
                'uuid',
                Rule::exists('attendance_logs', 'id')->where('employee_id', $this->user()?->employee?->id),
            ],
            'direction' => ['required_if:operation,add,amend', Rule::in(['in', 'out'])],
            'punched_at' => ['required_if:operation,add,amend', 'date'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
