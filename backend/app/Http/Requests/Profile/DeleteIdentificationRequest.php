<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteIdentificationRequest extends FormRequest
{
    /**
     * Two checks, not one: the actor may edit THIS employee, AND the identification in the
     * URL actually belongs to that employee. Without the second, an authorized HR Admin
     * could delete any identification in the system by pairing it with an employee they do
     * administer.
     */
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $identification = $this->route('identification');

        if (! $employee instanceof Employee || ! $identification instanceof EmployeeIdentification) {
            return false;
        }

        return $identification->employee_id === $employee->id
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [];
    }
}
