<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReopenCutoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /** A reopen is loudly audited and always requires a reason — see ReopenCutoff. */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
