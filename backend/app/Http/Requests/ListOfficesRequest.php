<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListOfficesRequest extends FormRequest
{
    /**
     * Sysadmin-only, mirroring ListOrganizationsRequest / ListPayRulesRequest. The
     * org tree is global config — nothing to enumerate — so a non-admin gets the
     * default failedAuthorization() (403 forbidden), never the 404-not-403 treatment
     * used for per-office/per-subject reads. `include_archived` and `organization` are
     * read directly off the request in the controller; neither needs validation beyond
     * what `Office::query()->where(...)` already tolerates.
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
