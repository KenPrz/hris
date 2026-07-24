<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    public function rules(): array
    {
        return [
            // No office_id here — the office is fixed by the route-bound holiday, not
            // something a caller may retarget in the body.
            'day_type' => ['required', Rule::in([
                'special_working',
                'special_non_working',
                'regular_holiday',
                'double_regular_holiday',
            ])],
            'name' => ['required', 'string'],
        ];
    }
}
