<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/**
 * A day of the week, 0=Monday..6=Sunday — the backing int IS the index, aligned 1:1 with
 * the frontend's weekdayIndex. The one int-backed coded set in the system: a weekday's
 * identity is genuinely an ordinal, unlike DayType where the string is the meaning.
 */
enum Weekday: int
{
    case Monday = 0;
    case Tuesday = 1;
    case Wednesday = 2;
    case Thursday = 3;
    case Friday = 4;
    case Saturday = 5;
    case Sunday = 6;
}
