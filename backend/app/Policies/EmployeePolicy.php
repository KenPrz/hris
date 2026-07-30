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

    /**
     * The full personnel file, including national IDs and dependents.
     *
     * Self, or an HR Admin who administers THIS employee's office. Deliberately not
     * `inScope()`: EmployeeScope composes self + direct reports + HR offices additively, so
     * a manager is inside their own report's scope — and a manager must get the redacted
     * view. The HR branch therefore reads the hr_admin_offices pivot directly.
     */
    public function viewFullProfile(User $user, Employee $employee): bool
    {
        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return true;
        }

        return $this->administersOfficeOf($user, $employee);
    }

    /**
     * Contact and assignment only. Anyone the scope already lets see this employee — which
     * is what admits the manager, and why this one IS `inScope()`.
     */
    public function viewRedactedProfile(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee);
    }

    /**
     * HR Admins configure; nobody edits their own PII, HR Admin included — separation of
     * duties on payroll-adjacent data, the same logic that stops a requester approving their
     * own request. This is an explicit self-denial that outranks the HR-office grant: a lone
     * HR Admin's own file is a System Admin's job.
     */
    public function updateProfile(User $user, Employee $employee): bool
    {
        if ($employee->user_id !== null && $employee->user_id === $user->id) {
            return false;
        }

        return $this->administersOfficeOf($user, $employee);
    }

    private function inScope(User $user, Employee $employee): bool
    {
        return EmployeeScope::visibleTo($user)->whereKey($employee->id)->exists();
    }

    /**
     * The two axes together: the verb (`employee.pii.edit`, catalogued in RbacSeeder since
     * M2 and first read here) and the scope (an hr_admin_offices row covering this
     * employee's current office). An employee with no office yet is administered by nobody.
     */
    private function administersOfficeOf(User $user, Employee $employee): bool
    {
        if ($employee->current_office_id === null) {
            return false;
        }

        return $user->can('employee.pii.edit')
            && $user->hrAdminOffices()->whereKey($employee->current_office_id)->exists();
    }
}
