<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Schedule\ScheduleResolver;
use App\Models\Employee;
use Carbon\CarbonPeriod;

/**
 * Which dates in a leave request's [start, end] range actually cost the employee a
 * scheduled working day. A leave range spans calendar days, but only the ones the
 * employee was actually due to work should be debited — a rest day inside the range
 * (already a day off) is never counted, and never charged.
 */
final class LeaveDays
{
    private function __construct() {}

    /** @return list<string> the dates in [$start, $end] that are scheduled working days */
    public static function scheduledWorkingDays(Employee $employee, string $start, string $end): array
    {
        $resolver = new ScheduleResolver;

        $days = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateString = $date->format('Y-m-d');
            $resolved = $resolver->resolve($employee, $dateString);

            if (! $resolved->isRestDay && $resolved->scheduledMinutes > 0) {
                $days[] = $dateString;
            }
        }

        return $days;
    }
}
