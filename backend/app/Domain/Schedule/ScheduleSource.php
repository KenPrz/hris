<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/** Which layer of the resolution chain produced a ResolvedSchedule — for UI transparency and tests. */
enum ScheduleSource: string
{
    case Override = 'override';
    case Employee = 'employee';
    case Department = 'department';
    case OfficeDefault = 'office_default';
}
