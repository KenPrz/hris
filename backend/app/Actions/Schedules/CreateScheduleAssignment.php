<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Exceptions\Domain\ScheduleAssignmentExists;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Creates one schedule assignment row for a target (an employee or a department —
 * exactly one, already enforced by the DB CHECK and the FormRequest's withValidator).
 * The target's office-scope check (does the caller administer it?) and the template's
 * office match already happened in the controller — this action trusts its input and
 * only writes.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary for the target's employees — an employee target recomputes just that employee;
 * a department target resolves its current members and merges forEmployee over each
 * (over-inclusion is safe — RecomputeRange dedups, and a department with zero current
 * members is a clean no-op).
 */
final class CreateScheduleAssignment
{
    public function execute(CreateScheduleAssignmentInput $in): ScheduleAssignment
    {
        return DB::transaction(function () use ($in): ScheduleAssignment {
            // Lock the target row so two admins assigning the same target-date can't
            // both pass the pre-check and race to the insert — the second blocks here,
            // then cleanly sees the committed row below. Mirrors CreateHoliday. The
            // partial unique indexes remain the ultimate backstop.
            if ($in->employeeId !== null) {
                Employee::query()->lockForUpdate()->findOrFail($in->employeeId);
                $targetType = 'employee';
                $targetId = $in->employeeId;
                $targetColumn = 'employee_id';
            } else {
                Department::query()->lockForUpdate()->findOrFail($in->departmentId);
                $targetType = 'department';
                $targetId = $in->departmentId;
                $targetColumn = 'department_id';
            }

            $duplicate = ScheduleAssignment::query()
                ->where($targetColumn, $targetId)
                ->whereDate('effective_from', $in->effectiveFrom)
                ->exists();

            if ($duplicate) {
                throw new ScheduleAssignmentExists($targetType, $targetId, $in->effectiveFrom);
            }

            $assignment = ScheduleAssignment::query()->create([
                'shift_template_id' => $in->shiftTemplateId,
                'employee_id' => $in->employeeId,
                'department_id' => $in->departmentId,
                'effective_from' => $in->effectiveFrom,
                'created_by' => $in->actorId,
            ]);

            DB::afterCommit(function () use ($in, $assignment): void {
                $pairs = $in->employeeId !== null
                    ? AffectedSummaries::forEmployee($in->employeeId)
                    : Employee::query()
                        ->where('current_department_id', $in->departmentId)
                        ->pluck('id')
                        ->flatMap(fn (string $employeeId): array => AffectedSummaries::forEmployee($employeeId))
                        ->all();

                RecomputeRange::dispatch(
                    $pairs,
                    RecomputeTrigger::ScheduleAssignment,
                    $assignment->id,
                    "Schedule assignment {$assignment->id} created effective {$in->effectiveFrom}",
                    $in->actorId,
                );
            });

            return $assignment;
        });
    }
}
