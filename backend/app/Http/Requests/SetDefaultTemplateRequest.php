<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetDefaultTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope and template-belongs-to-office checks are the controller's job
    }

    /**
     * Shape only — deliberately NO `exists:offices,id` / `exists:shift_templates,id`.
     * That would let a fabricated id 400 while an out-of-scope real one 404s in the
     * controller, reintroducing the enumeration oracle the 404-not-403 rule exists to close.
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'uuid'],
            'template_id' => ['required', 'uuid'],
        ];
    }
}
