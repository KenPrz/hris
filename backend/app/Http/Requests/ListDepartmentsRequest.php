<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListDepartmentsRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring ListOfficesRequest. The org tree is global config —
     * nothing to enumerate — so a non-admin gets the default failedAuthorization() (403
     * forbidden), never the 404-not-403 treatment used for per-office/per-subject reads.
     * `include_archived` and `office` are read directly off the request in the
     * controller; neither needs validation beyond what `Department::query()->where(...)`
     * already tolerates.
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
