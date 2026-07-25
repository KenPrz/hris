<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Scope\OfficeScope;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * There is no DeleteScheduleAssignment Action class (unlike Create's) — this delete is a
 * single unconditional write with no business rule beyond the scope check, so it stays
 * inline here. Wrapped in its own DB::transaction purely so DB::afterCommit (M5b Task 6)
 * fires only once the delete durably commits, mirroring every other config-change action.
 */
final class DeleteAssignmentController
{
    public function __invoke(Request $request, ScheduleAssignment $assignment): Response
    {
        // Same target-office resolution as CreateAssignmentController: an assignment's
        // office is its employee's current office, or its department's office.
        $officeId = $assignment->employee_id !== null
            ? $assignment->employee?->current_office_id
            : $assignment->department?->office_id;

        // 404, not 403: an assignment whose target office the caller doesn't administer
        // 404s exactly like a nonexistent {assignment}.
        if ($officeId === null || ! OfficeScope::administers($request->user(), $officeId)) {
            throw new NotFoundHttpException;
        }

        DB::transaction(function () use ($request, $assignment): void {
            $assignmentId = $assignment->id;
            $employeeId = $assignment->employee_id;
            $departmentId = $assignment->department_id;
            $actorId = $request->user()?->id;

            $assignment->delete();

            DB::afterCommit(function () use ($assignmentId, $employeeId, $departmentId, $actorId): void {
                $pairs = $employeeId !== null
                    ? AffectedSummaries::forEmployee($employeeId)
                    : Employee::query()
                        ->where('current_department_id', $departmentId)
                        ->pluck('id')
                        ->flatMap(fn (string $id): array => AffectedSummaries::forEmployee($id))
                        ->all();

                RecomputeRange::dispatch(
                    $pairs,
                    RecomputeTrigger::ScheduleAssignment,
                    $assignmentId,
                    "Schedule assignment {$assignmentId} deleted",
                    $actorId,
                );
            });
        });

        return response()->noContent();
    }
}
