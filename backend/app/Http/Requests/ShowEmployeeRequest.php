<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShowEmployeeRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring ListEmployeesRequest. The admin profiler show reads the
     * full employment record (base rate, art82 exemption) for any employee company-wide,
     * so — same as ListEmployeesRequest — a non-admin gets the default
     * failedAuthorization() (403 forbidden), not the 404-not-403 treatment
     * ShowEmployeeController (the scoped, non-admin GET /employees/{employee}) uses.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [];
    }
}
