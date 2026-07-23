<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated user; the "is an employee" check is in the action
    }

    /**
     * The EnsureIdempotency middleware passes unkeyed requests straight through — it only
     * guards replay of a key it has seen before. Requiring the header at all is this
     * request's job: fold it into the validated input so a missing key surfaces as the
     * ordinary 400 validation_failed envelope, not a silent bypass.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['_idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['in', 'out'])],
            '_idempotency_key' => ['required', 'string'],
        ];
    }
}
