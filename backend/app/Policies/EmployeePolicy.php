<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Scope\EmployeeScope;
use App\Models\Employee;
use App\Models\User;

/**
 * Two checks, always: the subject via EmployeeScope, and (for writes) the verb via a
 * permission. System admins never reach here — Gate::before short-circuits first.
 *
 * "Can see" is defined as "is inside the scope query", so there is one definition of the
 * boundary, shared with every index. See docs/05-rbac.md.
 */
final class EmployeePolicy
{
    public function view(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee) && $user->can('employee.manage');
    }

    private function inScope(User $user, Employee $employee): bool
    {
        return EmployeeScope::visibleTo($user)->whereKey($employee->id)->exists();
    }
}
