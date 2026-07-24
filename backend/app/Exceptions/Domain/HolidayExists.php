<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating a holiday whose (office_id, date) matches a row that already exists.
 * The unique constraint on holidays makes two holidays on the same office-date structurally
 * one; this turns that into a clean error instead of a database-constraint violation
 * surfacing as a 500. The office-scope 404 always runs first, so this is only ever reachable
 * for an office the caller already administers — it leaks nothing about other offices.
 */
final class HolidayExists extends DomainException
{
    public function __construct(private readonly string $officeId, private readonly string $date)
    {
        parent::__construct('This office already has a holiday on that date.');
    }

    public function errorCode(): string
    {
        return 'holiday_exists';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['office_id' => $this->officeId, 'date' => $this->date];
    }
}
