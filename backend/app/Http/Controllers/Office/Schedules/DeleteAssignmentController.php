<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Models\ScheduleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $assignment->delete();

        return response()->noContent();
    }
}
