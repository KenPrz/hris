<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when an approval's effect would change a day that falls inside a CLOSED cutoff
 * period. Raised by CutoffGuard::assertOpen, called from ApproveRequest on the final hop
 * under the affected employee's row lock — so the closed-period read races correctly
 * against a concurrent CloseCutoff (both hold that same per-employee lock). A locked period
 * is immutable: the correct remedy is to reopen the period (ReopenCutoff), not to force the
 * approval through.
 */
final class CutoffLocked extends DomainException
{
    public function __construct(private readonly string $date)
    {
        parent::__construct('This day falls in a closed cutoff period and can no longer be changed.');
    }

    public function errorCode(): string
    {
        return 'cutoff_locked';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['date' => $this->date];
    }
}
