<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Domain\Pay\DayType;
use App\Domain\Pay\PayMultiplier;
use App\Domain\Pay\SummaryLineKind;
use App\Domain\Time\MealBreakPolicy;
use App\Domain\Time\Minutes;
use App\Domain\Time\NightDiffSplitter;
use App\Domain\Time\OvertimeThreshold;
use App\Domain\Time\PunchPairer;
use App\Domain\Time\WorkInterval;

/**
 * Turns one employee-day's effective punches + day context + rates into the premium
 * buckets a daily_attendance_summary/daily_summary_lines pair persists. Pure — no DB,
 * no models, no config; every fact it needs arrives on DailyComputationInput.
 *
 * The pipeline:
 *  1. Pair the punches (PunchPairer). An odd count is incomplete: zero worked, no
 *     lines, no late/undertime — an adjustment request is how this gets fixed (M3.6),
 *     not a guess here.
 *  2. No punches at all is NOT incomplete — it's a day nobody was expected to clock
 *     for (rest day) or a day paid without attendance (holiday) or an absence (ordinary/
 *     special). See computeUnworkedDay().
 *  3. Otherwise: net the meal break out of the gross paired total, split the net into
 *     regular vs overtime against the schedule, split each of those chronologically
 *     into day vs night, and price each of the four resulting non-zero buckets.
 *
 * Break attribution: MealBreakPolicy returns a scalar net total, not a clock position,
 * so this class has to decide where in the timeline the deducted minutes disappear
 * from. It trims them off the END of the last paired interval (spilling into earlier
 * intervals only if the break is longer than the last one) — the same convention as
 * "the meal happens near the end of the gross span, whatever the gross span is." This
 * never affects lateness (computed from the untrimmed first punch) and never changes
 * the regular/overtime *total* (OvertimeThreshold works off the net Minutes total, not
 * a position), so its only observable effect is on which minutes the night/day split
 * attributes the break to. All of the test matrix's break-bearing cases run entirely
 * inside daylight hours, so this choice is inert for every case that matters today;
 * it exists so the pipeline has one documented, deterministic answer rather than an
 * unstated one.
 *
 * Regular/overtime x day/night attribution: rather than approximate with aggregate
 * totals, each paired (post-break) interval is sliced AT the regular/overtime boundary
 * (the point where the running chronological total crosses scheduledMinutes) into up
 * to two real WorkIntervals, and NightDiffSplitter runs on each slice directly — so the
 * night window's own cross-midnight recurrence logic is reused unmodified rather than
 * reimplemented at finer grain.
 */
final class DailyComputation
{
    public static function compute(DailyComputationInput $in): ComputedDay
    {
        $paired = PunchPairer::pair($in->punches);

        if ($paired->hasUnpaired()) {
            return new ComputedDay(
                workedMinutes: 0,
                lateMinutes: 0,
                undertimeMinutes: 0,
                unpaidOvertimeMinutes: 0,
                isIncomplete: true,
                lines: [],
            );
        }

        if ($paired->intervals === []) {
            return self::computeUnworkedDay($in);
        }

        $grossTotal = $paired->totalWorked();
        $net = MealBreakPolicy::assumed($in->breakMinutes, $in->mealBreakAppliesOverMinutes)->netWorked($grossTotal);
        $breakDeducted = $grossTotal->value - $net->value;

        $keptIntervals = self::trimTail($paired->intervals, $breakDeducted);

        $paidCeiling = $in->isArt82Exempt
            ? PHP_INT_MAX
            : $in->overtimeThresholdMinutes + $in->approvedOvertimeMinutes;

        [$regularDay, $regularNight, $overtimeDay, $overtimeNight, $excess] =
            self::splitBuckets($keptIntervals, $in->overtimeThresholdMinutes, $paidCeiling);

        $lines = self::buildLines($in, $regularDay, $regularNight, $overtimeDay, $overtimeNight);

        // Leave can coexist with worked time. A half-day leave whose other half is worked
        // must pay for both — this branch used to emit no leave line at all, because the
        // leave check lived only on the no-punches path, so such an employee was debited
        // half a day of balance and paid only for the hours they clocked.
        //
        // Capped at the unworked remainder of the scheduled day, so a full-day leave the
        // employee ignored and worked through pays once rather than twice, and a partially
        // worked day is topped up only by what is actually missing.
        $leaveCredit = self::leaveCredit($in, $net->value);

        if ($leaveCredit > 0) {
            $lines[] = new ComputedLine(
                kind: SummaryLineKind::LeaveWithPay,
                minutes: $leaveCredit,
                appliedBp: 10000,
            );
        }

        $firstPunch = $paired->intervals[0]->startMinute;
        $late = $in->scheduledStartMinute === null ? 0 : max(0, $firstPunch - $in->scheduledStartMinute);

        // Leave counts toward the scheduled day: an employee who worked half and holds leave
        // for the other half owes nothing.
        $undertime = OvertimeThreshold::undertime(
            Minutes::of($net->value + $leaveCredit),
            Minutes::of($in->scheduledMinutes),
        )->value;

        return new ComputedDay(
            workedMinutes: $net->value,
            lateMinutes: $late,
            undertimeMinutes: $undertime,
            unpaidOvertimeMinutes: $excess,
            isIncomplete: false,
            lines: $lines,
        );
    }

    /** A day with no punches at all: a rest day nobody was expected to work, a scheduled
     *  working day covered by approved leave, a paid holiday nobody worked, or a plain
     *  absence. Approved leave takes precedence over a paid holiday landing the same day
     *  (leave pays once, not leave + holiday premium). Never incomplete. */
    private static function computeUnworkedDay(DailyComputationInput $in): ComputedDay
    {
        $leaveCredit = self::leaveCredit($in, 0);

        // Leave counts toward the scheduled day — a day fully covered by leave is not
        // undertime. This used to pass zero unconditionally, so a full-day leave recorded a
        // full day of undertime beside its own leave_with_pay line.
        $undertime = OvertimeThreshold::undertime(
            Minutes::of($leaveCredit),
            Minutes::of($in->scheduledMinutes),
        )->value;

        $lines = [];

        if ($leaveCredit > 0) {
            // Leave wins over a paid holiday that happens to fall on the same day — an
            // employee on approved leave is paid for the leave, not paid twice (leave +
            // holiday premium). A leave-with-pay minute is a normal-day minute: flat
            // 10000 bp (100%), never routed through the premium matrix.
            //
            // Deliberately NOT gated on isArt82Exempt, unlike the holiday_unworked branch
            // below. Art. 82 exempts managerial/field personnel from PREMIUMS (overtime,
            // night differential, holiday pay) and from SIL accrual — not from base pay on
            // an approved paid leave they hold. leave_with_pay is base 100% (a normal day's
            // wage), not a premium, so an art82-exempt employee still receives it.
            $lines[] = new ComputedLine(
                kind: SummaryLineKind::LeaveWithPay,
                minutes: $leaveCredit,
                appliedBp: 10000,
            );
        } elseif (
            ! $in->isRestDay
            && ! $in->isArt82Exempt
            && self::isPaidHoliday($in->dayType)
            && $in->scheduledMinutes > 0
        ) {
            $lines[] = new ComputedLine(
                kind: SummaryLineKind::HolidayUnworked,
                minutes: $in->scheduledMinutes,
                appliedBp: PayMultiplier::forUnworkedDay($in->dayType, $in->isArt82Exempt, $in->rates)->value,
            );
        }

        return new ComputedDay(
            workedMinutes: 0,
            lateMinutes: 0,
            undertimeMinutes: $undertime,
            unpaidOvertimeMinutes: 0,
            isIncomplete: false,
            lines: $lines,
        );
    }

    /**
     * Paid-leave minutes this day can actually credit, given how much of it was worked.
     *
     * Never more than the day's unworked remainder, so leave and worked time cannot sum past
     * the scheduled day. Zero on a rest day (scheduledMinutes 0) — leave never charges a day
     * off, and there is nothing scheduled to top up.
     */
    private static function leaveCredit(DailyComputationInput $in, int $workedMinutes): int
    {
        if ($in->isRestDay) {
            return 0;
        }

        return min($in->leaveMinutes, max(0, $in->scheduledMinutes - $workedMinutes));
    }

    /** @return list<ComputedLine> */
    private static function buildLines(
        DailyComputationInput $in,
        int $regularDay,
        int $regularNight,
        int $overtimeDay,
        int $overtimeNight,
    ): array {
        $buckets = [
            [SummaryLineKind::RegularDay, $regularDay, false, false],
            [SummaryLineKind::RegularNight, $regularNight, false, true],
            [SummaryLineKind::OvertimeDay, $overtimeDay, true, false],
            [SummaryLineKind::OvertimeNight, $overtimeNight, true, true],
        ];

        $lines = [];

        foreach ($buckets as [$kind, $minutes, $isOvertime, $isNight]) {
            if ($minutes <= 0) {
                continue;
            }

            $bp = PayMultiplier::forWorkedTime(
                $in->dayType,
                $in->isRestDay,
                $isOvertime,
                $isNight,
                $in->isArt82Exempt,
                $in->rates,
            )->value;

            $lines[] = new ComputedLine($kind, $minutes, $bp);
        }

        return $lines;
    }

    /**
     * Trims $trim minutes off the chronological tail of $intervals — the last interval
     * first, spilling into earlier ones only if the break outlasts it.
     *
     * @param  list<WorkInterval>  $intervals
     * @return list<WorkInterval>
     */
    private static function trimTail(array $intervals, int $trim): array
    {
        if ($trim <= 0) {
            return $intervals;
        }

        $kept = [];
        $remaining = $trim;

        foreach (array_reverse($intervals) as $interval) {
            if ($remaining <= 0) {
                $kept[] = $interval;

                continue;
            }

            $duration = $interval->duration()->value;

            if ($duration <= $remaining) {
                $remaining -= $duration;

                continue;
            }

            $kept[] = WorkInterval::of($interval->startMinute, $interval->endMinute - $remaining);
            $remaining = 0;
        }

        return array_reverse($kept);
    }

    /**
     * Walks the (post-break) intervals in chronological order, attributing minutes to three
     * regions by two boundaries: regular below overtimeThreshold, PAID overtime between
     * overtimeThreshold and paidCeiling, and unpaid EXCESS beyond paidCeiling. Each priced
     * region is day/night split; the excess is a bare magnitude (unpaid — day/night is moot).
     *
     * @param  list<WorkInterval>  $intervals
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} regularDay, regularNight, overtimeDay, overtimeNight, excess
     */
    private static function splitBuckets(array $intervals, int $overtimeThreshold, int $paidCeiling): array
    {
        $regularDay = 0;
        $regularNight = 0;
        $overtimeDay = 0;
        $overtimeNight = 0;
        $excess = 0;
        $runningBefore = 0;

        foreach ($intervals as $interval) {
            // First boundary: regular vs. the rest (paid OT + excess).
            [$regularPart, $rest] = self::splitAtBoundary($interval, $runningBefore, $overtimeThreshold);

            if ($regularPart !== null) {
                $split = NightDiffSplitter::split($regularPart);
                $regularNight += $split->inside->value;
                $regularDay += $split->outside->value;
            }

            if ($rest !== null) {
                // Running total at the start of $rest: the boundary if the interval crossed
                // it, else where the interval began (it lies wholly beyond the threshold).
                $runningAtRest = max($runningBefore, $overtimeThreshold);

                // Second boundary: paid overtime vs. unpaid excess.
                [$paidPart, $excessPart] = self::splitAtBoundary($rest, $runningAtRest, $paidCeiling);

                if ($paidPart !== null) {
                    $split = NightDiffSplitter::split($paidPart);
                    $overtimeNight += $split->inside->value;
                    $overtimeDay += $split->outside->value;
                }

                if ($excessPart !== null) {
                    $excess += $excessPart->duration()->value;
                }
            }

            $runningBefore += $interval->duration()->value;
        }

        return [$regularDay, $regularNight, $overtimeDay, $overtimeNight, $excess];
    }

    /**
     * Slices one interval into its regular and overtime portions given how many
     * minutes were already worked before it started. Either part is null when the
     * whole interval falls on one side of the boundary.
     *
     * @return array{0: ?WorkInterval, 1: ?WorkInterval}
     */
    private static function splitAtBoundary(WorkInterval $interval, int $runningBefore, int $overtimeThreshold): array
    {
        $runningAfter = $runningBefore + $interval->duration()->value;

        if ($runningAfter <= $overtimeThreshold) {
            return [$interval, null];
        }

        if ($runningBefore >= $overtimeThreshold) {
            return [null, $interval];
        }

        $splitPoint = $interval->startMinute + ($overtimeThreshold - $runningBefore);

        return [
            WorkInterval::of($interval->startMinute, $splitPoint),
            WorkInterval::of($splitPoint, $interval->endMinute),
        ];
    }

    private static function isPaidHoliday(DayType $dayType): bool
    {
        return $dayType === DayType::RegularHoliday || $dayType === DayType::DoubleRegularHoliday;
    }
}
