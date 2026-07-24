<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CloneHolidaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    public function rules(): array
    {
        return [
            // Shape only — deliberately NO `exists:offices,id`. That would let a fake
            // office id 400 while an out-of-scope real one 404s in the controller,
            // reintroducing the enumeration oracle the 404-not-403 rule exists to close.
            'office_id' => ['required', 'uuid'],
            'from_year' => ['required', 'integer'],
            'to_year' => ['required', 'integer'],
        ];
    }
}
