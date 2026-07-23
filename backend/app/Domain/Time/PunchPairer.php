<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * The result of pairing a day's punches.
 */
final readonly class PairedPunches
{
    /** @param  list<WorkInterval>  $intervals */
    public function __construct(
        public array $intervals,
        public ?int $unpairedMinute,
    ) {}

    public function hasUnpaired(): bool
    {
        return $this->unpairedMinute !== null;
    }

    public function totalWorked(): Minutes
    {
        return Minutes::sum(array_map(
            static fn (WorkInterval $interval): Minutes => $interval->duration(),
            $this->intervals,
        ));
    }
}

/**
 * Turns an ordered list of punch minutes into intervals.
 *
 * Pairs **arbitrary even counts**, not just one in/out pair: meal breaks are
 * configurable per office, and an office on the explicit policy produces a four-punch
 * day. See docs/superpowers/specs/2026-07-23-hris-foundation-design.md.
 *
 * An odd count is reported, never guessed at. A punch-in with no punch-out computes as
 * zero paid hours and is flagged incomplete; the employee files an adjustment (M5).
 * Auto-closing at the scheduled end time would pay for time nobody verified, and would
 * silently conceal people who left early.
 */
final class PunchPairer
{
    /** @param  list<int>  $punchMinutes  Ascending, from the start of the business day. */
    public static function pair(array $punchMinutes): PairedPunches
    {
        self::assertOrdered($punchMinutes);

        $intervals = [];
        $count = count($punchMinutes);
        $pairable = $count - ($count % 2);

        for ($i = 0; $i < $pairable; $i += 2) {
            $intervals[] = WorkInterval::of($punchMinutes[$i], $punchMinutes[$i + 1]);
        }

        return new PairedPunches(
            intervals: $intervals,
            unpairedMinute: $count % 2 === 1 ? $punchMinutes[$count - 1] : null,
        );
    }

    /** @param  list<int>  $punchMinutes */
    private static function assertOrdered(array $punchMinutes): void
    {
        $previous = null;

        foreach ($punchMinutes as $minute) {
            if ($minute < 0) {
                throw new InvalidArgumentException("A punch minute cannot be negative: {$minute}.");
            }

            if ($previous !== null && $minute <= $previous) {
                throw new InvalidArgumentException(
                    "Punches must be in ascending order: {$previous} is followed by {$minute}."
                );
            }

            $previous = $minute;
        }
    }
}
