<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * The closed set of non-zero premium buckets a daily_summary_lines row can carry.
 *
 * A daily_attendance_summary is the day-level record (worked/scheduled/late/undertime
 * minutes); each line is one premium bucket that applied that day, in integer minutes at
 * an integer basis-point rate. A day with nothing but ordinary hours worked on schedule
 * has no lines at all — only non-zero buckets get a row.
 */
enum SummaryLineKind: string
{
    case RegularDay = 'regular_day';
    case RegularNight = 'regular_night';
    case OvertimeDay = 'overtime_day';
    case OvertimeNight = 'overtime_night';
    case HolidayUnworked = 'holiday_unworked';
    case LeaveWithPay = 'leave_with_pay';
}
