<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Thrown when CloseCutoff is asked to close a period that is already `closed`. */
final class CutoffAlreadyClosed extends DomainException
{
    public function __construct(private readonly string $cutoffPeriodId)
    {
        parent::__construct('This cutoff period has already been closed.');
    }

    public function errorCode(): string
    {
        return 'cutoff_already_closed';
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
