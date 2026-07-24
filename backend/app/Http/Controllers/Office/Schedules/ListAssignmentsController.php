<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListScheduleAssignmentsRequest;
use App\Http\Resources\ScheduleAssignmentResource;
use App\Models\ScheduleAssignment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListAssignmentsController
{
    public function __invoke(ListScheduleAssignmentsRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administered($request->user(), $request->string('office')->toString())
            ?? throw new NotFoundHttpException;

        // Unlike a shift template (its own office_id column), an assignment "belongs to"
        // an office through its target: the employee's current office, or the
        // department's office.
        $assignments = ScheduleAssignment::query()
            ->where(function ($query) use ($office): void {
                $query->whereHas('employee', fn ($q) => $q->where('current_office_id', $office->id))
                    ->orWhereHas('department', fn ($q) => $q->where('office_id', $office->id));
            })
            ->when($request->filled('employee'), fn ($query) => $query->where('employee_id', $request->string('employee')->toString()))
            ->when($request->filled('department'), fn ($query) => $query->where('department_id', $request->string('department')->toString()))
            ->orderBy('effective_from')
            ->get();

        return ScheduleAssignmentResource::collection($assignments);
    }
}
