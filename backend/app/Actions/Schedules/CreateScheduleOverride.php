<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Exceptions\Domain\ScheduleOverrideExists;
use App\Models\Employee;
use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

/**
 * Creates one schedule override row for an employee-date. The employee's office-scope
 * check (does the caller administer this employee's office?) already happened in the
 * controller — this action trusts its input and only writes.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary for this employee — forEmployee returns all of them rather than narrowing to
 * this override's own date; over-inclusion is safe (see AffectedSummaries' docblock).
 */
final class CreateScheduleOverride
{
    public function execute(CreateScheduleOverrideInput $in): ScheduleOverride
    {
        return DB::transaction(function () use ($in): ScheduleOverride {
            // Lock the employee row so two admins creating the same employee-date can't
            // both pass the pre-check and race to the insert — the second blocks here,
            // then cleanly sees the committed row below. Mirrors CreateHoliday /
            // CreateScheduleAssignment. The unique(employee_id, date) constraint remains
            // the ultimate backstop.
            Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            $duplicate = ScheduleOverride::query()
                ->where('employee_id', $in->employeeId)
                ->whereDate('date', $in->date)
                ->exists();

            if ($duplicate) {
                throw new ScheduleOverrideExists($in->employeeId, $in->date);
            }

            $override = ScheduleOverride::query()->create([
                'employee_id' => $in->employeeId,
                'date' => $in->date,
                'is_rest' => $in->isRest,
                'start_minute' => $in->startMinute,
                'end_minute' => $in->endMinute,
                'break_minutes' => $in->breakMinutes,
                'note' => $in->note,
                'created_by' => $in->actorId,
            ]);

            DB::afterCommit(function () use ($override, $in): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forEmployee($override->employee_id),
                    RecomputeTrigger::ScheduleOverride,
                    $override->id,
                    "Schedule override {$override->id} created for employee {$override->employee_id} on {$in->date}",
                    $in->actorId,
                );
            });

            return $override;
        });
    }
}
