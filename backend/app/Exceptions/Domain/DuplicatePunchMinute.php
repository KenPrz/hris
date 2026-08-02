<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a punch would land in an office-local minute this employee already has a
 * punch in.
 *
 * EffectivePunches truncates every punch to a whole minute before pairing, so two punches
 * inside one minute are indistinguishable downstream and collide in PunchPairer. Refusing
 * at ingestion is the only place that can still hand the caller an error — by the time the
 * compute runs, the punch is durable (DB::afterCommit) and the day is already broken.
 */
final class DuplicatePunchMinute extends DomainException
{
    public function __construct(private readonly string $localMinute)
    {
        parent::__construct('A punch already exists for this employee in that minute.');
    }

    public function errorCode(): string
    {
        return 'duplicate_punch_minute';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['local_minute' => $this->localMinute];
    }
}
