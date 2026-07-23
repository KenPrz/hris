<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Domain\Scope\EmployeeScope;
use App\Models\Request;
use App\Models\User;

/**
 * Who may decide a pending request: the requester must be visible to the approver under
 * EmployeeScope (self, direct reports, HR office, or system-admin-all) AND the approver
 * must not be the requester themselves — no self-approval, however broad the scope.
 *
 * A Domain query service that touches Eloquent via EmployeeScope, the same carve-out
 * given to that class by the framework-agnostic arch rule.
 */
final class RequestAuthority
{
    public static function canDecide(User $approver, Request $request): bool
    {
        return EmployeeScope::visibleTo($approver)->whereKey($request->employee_id)->exists()
            && $approver->employee?->id !== $request->employee_id;
    }
}
