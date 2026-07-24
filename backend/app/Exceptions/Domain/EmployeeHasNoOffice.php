<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when the schedule resolution chain falls through to the office-default layer and
 * the employee has no `current_office_id`. Without an office there is no default template
 * to fall back to, so resolution cannot proceed further.
 */
final class EmployeeHasNoOffice extends DomainException
{
    public function __construct(private readonly string $employeeId)
    {
        parent::__construct('This employee has no current office assigned.');
    }

    public function errorCode(): string
    {
        return 'employee_has_no_office';
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
