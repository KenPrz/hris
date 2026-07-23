<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// `decision_note` is deliberately NOT validated as `required` here. Doing so at the
// FormRequest layer would run before RejectRequest's authority (404) and pending (409)
// checks — route-model binding loads any existing request regardless of scope, so an
// out-of-scope prober sending an empty body would get 400 (proving the request exists)
// instead of 404 (indistinguishable from nonexistent). The required-note rule is
// enforced inside RejectRequest::execute, AFTER authority and pending, so ordering is
// authority (404) -> pending (409) -> note-validation (400).
final class RejectAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated user; RequestAuthority decides in the action (404 if not)
    }

    public function rules(): array
    {
        return [
            'decision_note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
