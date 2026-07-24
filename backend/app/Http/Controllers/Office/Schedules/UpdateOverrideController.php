<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\UpdateScheduleOverride;
use App\Actions\Schedules\UpdateScheduleOverrideInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\UpdateScheduleOverrideRequest;
use App\Http\Resources\ScheduleOverrideResource;
use App\Models\ScheduleOverride;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateOverrideController
{
    public function __invoke(UpdateScheduleOverrideRequest $request, ScheduleOverride $override, UpdateScheduleOverride $action): JsonResponse
    {
        // 404, not 403: an override whose employee's office the caller doesn't administer
        // 404s exactly like a nonexistent {override}. The null-office check comes first —
        // administers() is string-typed, so a null current_office_id would TypeError into a
        // 500 rather than 404 (matches ResolvedScheduleController / CreateOverrideController).
        if ($override->employee->current_office_id === null
            || ! OfficeScope::administers($request->user(), $override->employee->current_office_id)) {
            throw new NotFoundHttpException;
        }

        $updated = $action->execute($override, new UpdateScheduleOverrideInput(
            isRest: $request->boolean('is_rest'),
            startMinute: $request->filled('start_minute') ? $request->integer('start_minute') : null,
            endMinute: $request->filled('end_minute') ? $request->integer('end_minute') : null,
            breakMinutes: $request->filled('break_minutes') ? $request->integer('break_minutes') : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
        ));

        return ScheduleOverrideResource::make($updated)->response();
    }
}
