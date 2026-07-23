<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/** Widens as request types are added (leave, overtime); attendance adjustment is first. */
enum RequestType: string
{
    case AttendanceAdjustment = 'attendance_adjustment';
}
