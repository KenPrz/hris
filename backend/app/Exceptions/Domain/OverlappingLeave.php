<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a leave approval's date span overlaps leave this employee already holds
 * approved.
 *
 * Without it, two overlapping approved requests each wrote a ledger debit while the compute
 * path emitted one leave_with_pay line per day: charged twice, paid once.
 */
final class OverlappingLeave extends DomainException
{
    public function __construct(private readonly string $conflictingRequestId)
    {
        parent::__construct('This leave overlaps a request that is already approved.');
    }

    public function errorCode(): string
    {
        return 'overlapping_leave';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['conflicting_request_id' => $this->conflictingRequestId];
    }
}
