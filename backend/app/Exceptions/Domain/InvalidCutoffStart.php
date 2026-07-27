<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a cutoff's requested start date isn't a valid semi-monthly boundary — the
 * 1st or the 16th (see CutoffCalendar::isValidStart).
 */
final class InvalidCutoffStart extends DomainException
{
    public function __construct(private readonly string $periodStart)
    {
        parent::__construct('A cutoff period must start on the 1st or the 16th of the month.');
    }

    public function errorCode(): string
    {
        return 'invalid_cutoff_start';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['period_start' => $this->periodStart];
    }
}
