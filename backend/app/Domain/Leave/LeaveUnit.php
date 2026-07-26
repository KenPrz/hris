<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use InvalidArgumentException;

/**
 * Readable-unit <-> minutes conversion. A leave request is filed in a unit an employee
 * understands (days, half a shift, hours, minutes); every downstream computation — the
 * ledger, the balance — works in integer minutes only. This is the one place that
 * translates between the two, and it never stores anything: pure static helpers, no
 * framework dependency.
 */
final class LeaveUnit
{
    private function __construct() {}

    public static function toMinutes(int $amount, string $unit, int $minutesPerLeaveDay): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A leave amount must be positive: {$amount}.");
        }

        return match ($unit) {
            'day' => $amount * $minutesPerLeaveDay,
            'half_shift' => $amount * intdiv($minutesPerLeaveDay, 2),
            'hour' => $amount * 60,
            'minute' => $amount,
            default => throw new InvalidArgumentException("Unknown leave unit: {$unit}."),
        };
    }

    /** @return array{days: int, hours: int, minutes: int} */
    public static function readable(int $minutes, int $minutesPerLeaveDay): array
    {
        $days = intdiv($minutes, $minutesPerLeaveDay);
        $remainder = $minutes % $minutesPerLeaveDay;

        $hours = intdiv($remainder, 60);
        $remainderMinutes = $remainder % 60;

        return ['days' => $days, 'hours' => $hours, 'minutes' => $remainderMinutes];
    }
}
