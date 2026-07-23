<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when an HR/system admin actor attempts to manually record a punch for their own
 * employee record. Manual entry exists so HR can backfill or correct punches for other
 * people (especially login-less punch-only workers); it is never how anyone records their
 * own attendance — that is the self-service clock-in, whose caller-supplied-timestamp
 * protection this rule preserves by closing the "manually punch myself" loophole.
 */
final class CannotManuallyPunchSelf extends DomainException
{
    public function __construct()
    {
        parent::__construct('Record your own attendance through a clock-in, not manual entry.');
    }

    public function errorCode(): string
    {
        return 'cannot_punch_self';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
