<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Thrown when ExportPayroll (or similar) is asked to export a period that is not `closed`. */
final class PeriodNotExportable extends DomainException
{
    public function __construct(private readonly string $cutoffPeriodId, private readonly string $state)
    {
        parent::__construct('This cutoff period is not exportable.');
    }

    public function errorCode(): string
    {
        return 'period_not_exportable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['cutoff_period_id' => $this->cutoffPeriodId, 'state' => $this->state];
    }
}
