<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SaveIdentificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', 'exists:employee_identification_categories,id'],
            'number' => ['required', 'string', 'max:64'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:512'],
            // Same limits as Request's attachment collection (SubmitAdjustmentRequest).
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
