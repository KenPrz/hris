<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesShiftDays;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateShiftTemplateRequest extends FormRequest
{
    use ValidatesShiftDays;

    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /**
     * No `office_id` here — the office is fixed by the route-bound {template}, not the
     * body. Same day shape as create: exactly seven entries, re-validated in full (a
     * PATCH replaces the whole week, never a partial day).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6'],
            'days.*.is_rest' => ['required', 'boolean'],
            'days.*.start_minute' => ['nullable', 'integer', 'between:0,1439', 'required_if:days.*.is_rest,false'],
            'days.*.end_minute' => ['nullable', 'integer', 'between:1,2879', 'required_if:days.*.is_rest,false'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'required_if:days.*.is_rest,false'],
        ];
    }
}
