<?php

declare(strict_types=1);

namespace App\Domain\Compute;

/**
 * The closed set of config changes that can trigger a RecomputeRange run.
 *
 * A recompute_runs row always names which one of these fired it — the audit trail
 * behind "why did this day's numbers change, and when."
 */
enum RecomputeTrigger: string
{
    case Holiday = 'holiday';
    case PayRule = 'pay_rule';
    case ShiftTemplate = 'shift_template';
    case ScheduleAssignment = 'schedule_assignment';
    case ScheduleOverride = 'schedule_override';
    case OfficeDefault = 'office_default';
    case Leave = 'leave';
}
