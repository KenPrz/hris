<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\SetDefaultTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets the office-wide fallback ScheduleResolver reaches once no employee or department
 * assignment covers a date. Office has no LogsActivity trait (unlike ShiftTemplate,
 * ScheduleAssignment, ScheduleOverride), so this logs manually against the office, the
 * same way CloneHolidays does.
 *
 * There is no SetOfficeDefaultTemplate Action class — this is a single unconditional write
 * with no business rule beyond the scope/office-match checks above, so it stays inline
 * here, wrapped in its own DB::transaction purely so DB::afterCommit (M5b Task 6) fires
 * only once the update durably commits. Enqueues an audited recompute of every EXISTING
 * summary in the office — any employee falling through to the office default is affected.
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

        DB::transaction(function () use ($request, $office, $template): void {
            $office->update(['default_shift_template_id' => $template->id]);

            activity()
                ->causedBy($request->user())
                ->performedOn($office)
                ->withProperties(['default_shift_template_id' => $template->id])
                ->log('set default shift template');

            DB::afterCommit(function () use ($request, $office): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forOffice($office->id),
                    RecomputeTrigger::OfficeDefault,
                    $office->id,
                    "Office {$office->id} default shift template changed",
                    $request->user()?->id,
                );
            });
        });

        return response()->json(['data' => [
            'id' => $office->id,
            'default_shift_template_id' => $office->default_shift_template_id,
        ]]);
    }
}
