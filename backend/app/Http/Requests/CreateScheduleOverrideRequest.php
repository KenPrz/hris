<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesScheduleOverrideShape;
use Illuminate\Foundation\Http\FormRequest;

final class CreateScheduleOverrideRequest extends FormRequest
{
    use ValidatesScheduleOverrideShape;

    public function authorize(): bool
    {
        return true;   // the employee's office-scope check is the controller's job
    }

    /**
     * Shape only — deliberately NO `exists:employees,id`. That would let a fabricated
     * employee id 400 while an out-of-scope real one 404s in the controller,
     * reintroducing the enumeration oracle the 404-not-403 rule exists to close.
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_rest' => ['required', 'boolean'],
            'start_minute' => ['nullable', 'integer'],
            'end_minute' => ['nullable', 'integer'],
            'break_minutes' => ['nullable', 'integer'],
            'note' => ['nullable', 'string'],
        ];
    }
}
