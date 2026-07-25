<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Exceptions\Domain\TemplateInUse;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one shift template — but only if nothing still depends on it. A template is
 * "in use" two ways: it is its office's `default_shift_template_id` (deleting it would
 * leave the office with no fallback schedule), or a ScheduleAssignment still points at it
 * (deleting it would leave that assignment dangling). Either guard trips before any write,
 * so a rejected delete leaves the row and its days untouched. The office-scope check (does
 * the caller administer this template's office?) already happened in the controller.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) — always a clean
 * no-op in practice, since the in-use guard above already proved no assignment or office
 * default points at this template, so forShiftTemplate finds nothing. Wired anyway for
 * uniformity with Create/UpdateShiftTemplate.
 */
final class DeleteShiftTemplate
{
    public function execute(ShiftTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            if ($template->office->default_shift_template_id === $template->id
                || ScheduleAssignment::where('shift_template_id', $template->id)->exists()) {
                throw new TemplateInUse($template->id);
            }

            $officeId = $template->office_id;
            $templateId = $template->id;

            $template->delete();

            DB::afterCommit(function () use ($templateId, $officeId): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forShiftTemplate($templateId),
                    RecomputeTrigger::ShiftTemplate,
                    $templateId,
                    "Shift template {$templateId} deleted for office {$officeId}",
                );
            });
        });
    }
}
