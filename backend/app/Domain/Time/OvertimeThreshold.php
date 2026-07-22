<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Divides worked minutes into regular and overtime against a scheduled day.
 *
 * The threshold is the schedule's own length, not a fixed eight hours: a compressed
 * 4x10 workweek has a ten-hour scheduled day, so hour nine is regular time (DOLE
 * Department Advisory 02-04).
 *
 * Undertime is deliberately *not* negative overtime. It is a separate non-negative
 * magnitude, because it appears as its own line on a payslip and because a negative
 * duration is not representable in Minutes.
 *
 * Note this splits *worked* time only. Whether the overtime is payable is a different
 * question, answered in M5: the engine pays min(actual, approved) against a pre-filed
 * overtime authorization, and shows the remainder as unpaid excess time.
 */
final class OvertimeThreshold
{
    /** `inside` is regular time; `outside` is overtime. */
    public static function split(Minutes $worked, Minutes $scheduled): WorkedSplit
    {
        $regular = $worked->min($scheduled);

        return WorkedSplit::of(
            inside: $regular,
            outside: $worked->minus($regular),
        );
    }

    public static function undertime(Minutes $worked, Minutes $scheduled): Minutes
    {
        return $scheduled->minus($scheduled->min($worked));
    }
}
