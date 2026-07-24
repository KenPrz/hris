<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating a schedule override whose (employee_id, date) matches a row that
 * already exists — an employee can only have one override on a given date. The
 * unique(employee_id, date) constraint makes two overrides on the same employee-date
 * structurally one; this turns that into a clean error instead of a database-constraint
 * violation surfacing as a 500. The employee's office-scope 404 always runs first, so this
 * is only ever reachable for an employee the caller already administers — it leaks nothing
 * about other offices.
 */
final class ScheduleOverrideExists extends DomainException
{
    public function __construct(private readonly string $employeeId, private readonly string $date)
    {
        parent::__construct('This employee already has a schedule override on that date.');
    }

    public function errorCode(): string
    {
        return 'schedule_override_exists';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['employee_id' => $this->employeeId, 'date' => $this->date];
    }
}
