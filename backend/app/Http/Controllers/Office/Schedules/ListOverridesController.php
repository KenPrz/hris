<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListScheduleOverridesRequest;
use App\Http\Resources\ScheduleOverrideResource;
use App\Models\Employee;
use App\Models\ScheduleOverride;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListOverridesController
{
    public function __invoke(ListScheduleOverridesRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administered($request->user(), $request->string('office')->toString())
            ?? throw new NotFoundHttpException;

        // The employee must belong to the office being listed — an employee from another
        // office (or a fabricated id) 404s identically; scoping the lookup by
        // current_office_id keeps both cases indistinguishable.
        $employee = Employee::query()
            ->where('current_office_id', $office->id)
            ->find($request->string('employee')->toString())
            ?? throw new NotFoundHttpException;

        [$year, $month] = explode('-', $request->string('month')->toString());

        $overrides = ScheduleOverride::query()
            ->where('employee_id', $employee->id)
            ->whereYear('date', (int) $year)
            ->whereMonth('date', (int) $month)
            ->orderBy('date')
            ->get();

        return ScheduleOverrideResource::collection($overrides);
    }
}
