<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesScheduleOverrideShape;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateScheduleOverrideRequest extends FormRequest
{
    use ValidatesScheduleOverrideShape;

    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /**
     * No employee_id or date here — both are fixed by the route-bound override, not
     * something a caller may retarget in the body (mirrors UpdateHolidayRequest omitting
     * office_id).
     */
    public function rules(): array
    {
        return [
            'is_rest' => ['required', 'boolean'],
            'start_minute' => ['nullable', 'integer'],
            'end_minute' => ['nullable', 'integer'],
            'break_minutes' => ['nullable', 'integer'],
            'note' => ['nullable', 'string'],
        ];
    }
}
