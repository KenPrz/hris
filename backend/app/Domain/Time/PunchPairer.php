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
        $outOfOrder = self::firstOutOfOrder($punchMinutes);

        if ($outOfOrder !== null) {
            // Reported as incomplete, not thrown.
            //
            // EffectivePunches truncates each punch to a whole minute, so two punches inside
            // one minute — a double-tap, two open tabs, an HR admin entering 08:00 twice
            // through the deliberately non-idempotent manual route — collide here. This used
            // to throw, and by then the punch was already durable: ComputeDailySummary runs
            // in DB::afterCommit, outside the writing transaction. So the throw escaped as a
            // 500 and NO summary row was ever written for that day — and a day with no
            // summary row is invisible to CloseCutoff's incomplete-day gate, so the period
            // closed with the day worth zero. Every later recompute threw identically, which
            // made it permanent.
            //
            // An incomplete day is the right answer instead: it is exactly what an unpaired
            // punch already produces, it blocks the cutoff close, and it puts the day in
            // front of an HR admin to resolve through the adjustment flow. RecordPunch also
            // refuses a duplicate minute at ingestion now (PunchOrdering), so this path only
            // ever sees rows written before that guard existed.
            return new PairedPunches(intervals: [], unpairedMinute: $outOfOrder);
        }

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

    /**
     * The first minute that is not strictly greater than the one before it, or null when the
     * list is properly ascending.
     *
     * A negative minute still throws: EffectivePunches measures from the window start, which
     * is never after the first punch, so a negative is unreachable from real data and means
     * the window arithmetic itself is broken — not something to absorb as an incomplete day.
     *
     * @param  list<int>  $punchMinutes
     */
    private static function firstOutOfOrder(array $punchMinutes): ?int
    {
        $previous = null;

        foreach ($punchMinutes as $minute) {
            if ($minute < 0) {
                throw new InvalidArgumentException("A punch minute cannot be negative: {$minute}.");
            }

            if ($previous !== null && $minute <= $previous) {
                return $minute;
            }

            $previous = $minute;
        }

        return null;
    }
}
