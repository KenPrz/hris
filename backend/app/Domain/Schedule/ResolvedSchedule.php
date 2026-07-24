<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/** The resolved schedule for one (employee, date). scheduledMinutes = (end-start)-break, 0 when rest. */
final class ResolvedSchedule
{
    public function __construct(
        public readonly bool $isRestDay,
        public readonly ?int $startMinute,
        public readonly ?int $endMinute,
        public readonly ?int $breakMinutes,
        public readonly int $scheduledMinutes,
        public readonly ScheduleSource $source,
    ) {}

    public static function rest(ScheduleSource $source): self
    {
        return new self(true, null, null, null, 0, $source);
    }

    public static function working(int $start, int $end, int $break, ScheduleSource $source): self
    {
        return new self(false, $start, $end, $break, ($end - $start) - $break, $source);
    }
}
