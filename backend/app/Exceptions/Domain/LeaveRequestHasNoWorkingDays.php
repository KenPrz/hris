<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a leave request's [start, end] range contains zero scheduled working days
 * (e.g. a range that falls entirely on rest days) — there is nothing to debit, so the
 * request cannot be filed. See LeaveDays::scheduledWorkingDays.
 */
final class LeaveRequestHasNoWorkingDays extends DomainException
{
    public function __construct()
    {
        parent::__construct('This date range has no scheduled working days to charge leave against.');
    }

    public function errorCode(): string
    {
        return 'leave_request_has_no_working_days';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
