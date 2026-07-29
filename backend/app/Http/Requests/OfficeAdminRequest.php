<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OfficeAdminRequest extends FormRequest
{
    /**
     * Sysadmin-only, shared by ArchiveController and UnarchiveController, mirroring
     * PayRuleAdminRequest (shared by PayRules' ShowController/DeleteController). Both
     * transitions bind `{office}` via route-model-binding and take no body, so there is
     * nothing to validate beyond the gate itself.
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
