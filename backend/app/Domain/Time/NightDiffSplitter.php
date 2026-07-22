<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Splits a worked interval against the night-differential window, 22:00-06:00 (Art. 86).
 *
 * Works on integer minutes from the start of the business day, so a shift crossing
 * midnight needs no special case and no timezone database: the window simply recurs
 * every 1440 minutes, and the splitter walks as many recurrences as the interval spans.
 *
 * Night differential is 10% of the hourly rate *for that hour*, so these minutes get
 * multiplied by whatever premium already applies — see PayMultiplier.
 */
final class NightDiffSplitter
{
    /** 22:00, as minutes from midnight. */
    public const int WINDOW_START_MINUTE = 1_320;

    /** 06:00, as minutes from midnight. The window wraps, so this is less than the start. */
    public const int WINDOW_END_MINUTE = 360;

    private const int MINUTES_PER_DAY = 1_440;

    public static function split(WorkInterval $interval): WorkedSplit
    {
        $night = 0;

        // The window recurs daily. Walk one day before the interval starts through one
        // day after it ends, so a band straddling either edge is still counted.
        $firstDay = intdiv($interval->startMinute, self::MINUTES_PER_DAY) - 1;
        $lastDay = intdiv($interval->endMinute, self::MINUTES_PER_DAY) + 1;

        for ($day = $firstDay; $day <= $lastDay; $day++) {
            $offset = $day * self::MINUTES_PER_DAY;

            $night += self::overlap(
                $interval->startMinute,
                $interval->endMinute,
                $offset + self::WINDOW_START_MINUTE,
                $offset + self::MINUTES_PER_DAY + self::WINDOW_END_MINUTE,
            );
        }

        return WorkedSplit::of(
            inside: Minutes::of($night),
            outside: Minutes::of($interval->duration()->value - $night),
        );
    }

    private static function overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): int
    {
        return max(0, min($aEnd, $bEnd) - max($aStart, $bStart));
    }
}
