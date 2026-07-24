<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

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

            $template->delete();
        });
    }
}
