<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListMySummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // self-scoped: the controller resolves the caller's own employee
    }

    /**
     * Self only — no target-employee id. Taking one here would be an enumeration hole;
     * the caller may only ever read their own computed month.
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
