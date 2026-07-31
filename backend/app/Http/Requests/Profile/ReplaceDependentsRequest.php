<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReplaceDependentsRequest extends FormRequest
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
            // `present` not `required`: an empty array is a legitimate instruction
            // ("this employee has no dependents"), and `required` rejects [].
            'dependents' => ['present', 'array', 'max:20'],
            'dependents.*.name' => ['required', 'string', 'max:255'],
            'dependents.*.relationship_id' => ['required', 'uuid', 'exists:relationships,id'],
            'dependents.*.birth_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
