<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/** Where the punch came from. `device` exists in the contract; its auth path is deferred. */
enum PunchSource: string
{
    case Web = 'web';
    case Manual = 'manual';
    case Device = 'device';
    case Adjustment = 'adjustment';
}
