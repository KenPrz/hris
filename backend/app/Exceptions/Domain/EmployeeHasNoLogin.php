<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when SetHrAdminOffices is asked to grant HR-Admin access to an employee who has
 * no `user_id` — there is no User row to attach the pivot/role to. HR-Admin access is
 * granted to a login, not an employee record.
 */
final class EmployeeHasNoLogin extends DomainException
{
    public function __construct(private readonly string $employeeId)
    {
        parent::__construct('This employee has no login.');
    }

    public function errorCode(): string
    {
        return 'employee_has_no_login';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['employee_id' => $this->employeeId];
    }
}
