<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\SetDefaultTemplateRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets the office-wide fallback ScheduleResolver reaches once no employee or department
 * assignment covers a date. Office has no LogsActivity trait (unlike ShiftTemplate,
 * ScheduleAssignment, ScheduleOverride), so this logs manually against the office, the
 * same way CloneHolidays does.
 */
final class SetDefaultTemplateController
{
    public function __invoke(SetDefaultTemplateRequest $request): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // SetDefaultTemplateRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administered($request->user(), $request->string('office_id')->toString())
            ?? throw new NotFoundHttpException;

        // The template must belong to this same office — resolving it scoped to $office
        // means a template from another office and a fabricated template id 404
        // identically, alongside an out-of-scope office. No enumeration oracle.
        $template = $office->shiftTemplates()->find($request->string('template_id')->toString())
            ?? throw new NotFoundHttpException;

        $office->update(['default_shift_template_id' => $template->id]);

        activity()
            ->causedBy($request->user())
            ->performedOn($office)
            ->withProperties(['default_shift_template_id' => $template->id])
            ->log('set default shift template');

        return response()->json(['data' => [
            'id' => $office->id,
            'default_shift_template_id' => $office->default_shift_template_id,
        ]]);
    }
}
