<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/** Widens as request types are added (leave, overtime); attendance adjustment is first. */
enum RequestType: string
{
    case AttendanceAdjustment = 'attendance_adjustment';
    case Leave = 'leave';

    /** Whether this type is a two-hop (manager -> HR) flow, vs. single-hop manager-only. */
    public function requiresHrStep(): bool
    {
        return match ($this) {
            self::AttendanceAdjustment => false,
            self::Leave => true,
        };
    }
}
