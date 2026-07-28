<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListEmployeesRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring ListOfficesRequest / ListPayRulesRequest. This is the
     * company-wide profiler view (every employee, any office), distinct from the
     * scope-filtered GET /employees an ordinary manager/HR actor uses — so a non-admin
     * gets the default failedAuthorization() (403 forbidden), never the 404-not-403
     * treatment used for per-subject reads.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'office' => ['nullable', 'uuid'],
        ];
    }
}
