<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when recording an employment change whose (employee_id, effective_from) matches
 * a row that already exists. The unique constraint on employment_records makes two changes
 * on the same day structurally one change; this turns that into a clean error instead of a
 * database-constraint violation surfacing as a 500.
 */
final class EmploymentRecordExists extends DomainException
{
    public function __construct(private readonly string $employeeId, private readonly string $effectiveFrom)
    {
        parent::__construct('This employee already has an employment change recorded for that date.');
    }

    public function errorCode(): string
    {
        return 'employment_record_exists';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['employee_id' => $this->employeeId, 'effective_from' => $this->effectiveFrom];
    }
}
