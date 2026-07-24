<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecordEmploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    /**
     * 404, not the default 403 — the employee id is in the URL. See ProvisionUserRequest
     * for the full reasoning: a 403-for-real / 404-for-nonexistent split would let any
     * authenticated user enumerate which employee ids exist. A non-admin gets a uniform 404.
     */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'office_id' => ['required', 'uuid', 'exists:offices,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
            'reports_to_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'employment_type' => ['required', 'string'],
            'is_art82_exempt' => ['required', 'boolean'],
            'base_rate_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
