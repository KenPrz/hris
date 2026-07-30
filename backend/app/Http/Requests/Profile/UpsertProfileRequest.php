<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpsertProfileRequest extends FormRequest
{
    /**
     * NOT is_system_admin like its neighbours under /admin — the requirement is that HR
     * Admins configure profiles. Gate::before still short-circuits a system admin to true.
     */
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    /** 404, not 403 — the employee id is in the URL; see docs/05-rbac.md. */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'salutation' => ['nullable', 'string', 'max:16'],
            'nickname' => ['nullable', 'string', 'max:64'],
            'home_address' => ['nullable', 'string', 'max:512'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'fax' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            // Rule::enum matches the BACKED VALUE exactly — 'male', never 'Male'. That
            // strictness is the point: one spelling reaches the database.
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'birthplace' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'citizenship' => ['nullable', 'string', 'max:64'],
            'religion' => ['nullable', 'string', 'max:64'],
            'blood_type' => ['nullable', Rule::enum(BloodType::class)],
        ];
    }
}
