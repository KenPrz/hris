<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManualPunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Manual entry is an HR/admin operation, never a self-service one: a plain
        // employee or plain manager cannot manually enter punches at all, so this is a
        // 403, not a 404 — it leaks no specific employee's existence. The per-subject
        // scope check (404-not-403 for an out-of-scope target) and the self-punch check
        // (422) both belong in the controller, since they depend on the target employee.
        $user = $this->user();

        return $user->is_system_admin || $user->hrAdminOffices()->exists();
    }

    public function rules(): array
    {
        return [
            // No `exists:employees,id` on purpose: it runs before the controller's scope
            // check, so a real-but-out-of-scope id (controller → 404) and a nonexistent id
            // (exists → 400) would return different statuses, letting an HR admin enumerate
            // who exists company-wide. The controller resolves the id and 404s uniformly for
            // both a missing and an out-of-scope employee.
            'employee_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'punched_at' => ['required', 'date'],
        ];
    }
}
