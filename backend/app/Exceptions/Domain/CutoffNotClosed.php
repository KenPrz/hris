<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Thrown when ReopenCutoff is asked to reopen a period that is not `closed`. */
final class CutoffNotClosed extends DomainException
{
    public function __construct(private readonly string $cutoffPeriodId)
    {
        parent::__construct('This cutoff period is not closed.');
    }

    public function errorCode(): string
    {
        return 'cutoff_not_closed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['cutoff_period_id' => $this->cutoffPeriodId];
    }
}
