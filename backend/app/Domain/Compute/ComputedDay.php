<?php

declare(strict_types=1);

namespace App\Domain\Compute;

/**
 * The result of pricing one employee's business day: the day-level minute totals plus
 * the list of non-zero premium lines. Pure output — Task 5 is the only place this gets
 * persisted (as a daily_attendance_summaries row + daily_summary_lines rows).
 */
final readonly class ComputedDay
{
    /** @param  list<ComputedLine>  $lines */
    public function __construct(
        public int $workedMinutes,
        public int $lateMinutes,
        public int $undertimeMinutes,
        public int $unpaidOvertimeMinutes,
        public bool $isIncomplete,
        public array $lines,
    ) {}
}
