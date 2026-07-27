<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated employee files their own; NotAnEmployee is the controller's guard
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            // Hours in 0.25 steps, client-facing; the controller converts to integer minutes.
            'hours' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string'],
        ];
    }
}
