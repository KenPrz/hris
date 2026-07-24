<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the target/template office-scope checks are the controller's job
    }

    /**
     * Shape only — deliberately NO `exists:employees,id` / `exists:departments,id` /
     * `exists:shift_templates,id`. That would let a fabricated id 400 while an
     * out-of-scope real one 404s in the controller, reintroducing the enumeration
     * oracle the 404-not-403 rule exists to close.
     */
    public function rules(): array
    {
        return [
            'shift_template_id' => ['required', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'effective_from' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasEmployee = $this->filled('employee_id');
            $hasDepartment = $this->filled('department_id');

            if ($hasEmployee === $hasDepartment) {
                // Both present, or neither — exactly one target is required.
                $validator->errors()->add('employee_id', 'exactly one of employee_id or department_id is required');
                $validator->errors()->add('department_id', 'exactly one of employee_id or department_id is required');
            }
        });
    }
}
