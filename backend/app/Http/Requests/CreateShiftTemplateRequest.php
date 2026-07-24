<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesShiftDays;
use Illuminate\Foundation\Http\FormRequest;

final class CreateShiftTemplateRequest extends FormRequest
{
    use ValidatesShiftDays;

    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /**
     * Shape only — deliberately NO `exists:offices,id`. That would let a fake office id
     * 400 while an out-of-scope real one 404s in the controller, reintroducing the
     * enumeration oracle the 404-not-403 rule exists to close.
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'uuid'],
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
