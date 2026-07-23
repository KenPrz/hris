<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when provisioning a login for an employee who already has a user_id set.
 * An employee has at most one login; re-provisioning must be an explicit, separate
 * operation (not implemented here), never a silent overwrite.
 */
final class EmployeeAlreadyHasLogin extends DomainException
{
    public function __construct(private readonly string $employeeId)
    {
        parent::__construct('This employee already has a login.');
    }

    public function errorCode(): string
    {
        return 'employee_already_has_login';
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
