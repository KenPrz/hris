<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\ShiftTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Updates one shift template's name and replaces its seven weekday rows wholesale. The
 * office-scope check (does the caller administer this template's office?) already
 * happened in the controller — this action trusts its input and only writes. A PATCH
 * replaces the whole week rather than patching individual days: delete-then-reinsert
 * keeps the seven rows internally consistent without a separate diff/merge path, and one
 * transaction keeps the delete and the reinsert atomic. Spatie's LogsActivity on
 * ShiftTemplate records the `updated` event itself, with the causer resolved from the
 * authenticated guard automatically.
 */
final class UpdateShiftTemplate
{
    public function execute(ShiftTemplate $template, UpdateShiftTemplateInput $in): ShiftTemplate
    {
        return DB::transaction(function () use ($template, $in): ShiftTemplate {
            $template->update(['name' => $in->name]);

            $template->days()->delete();

            foreach ($in->days as $day) {
                $isRest = (bool) $day['is_rest'];

                $template->days()->create([
                    'weekday' => $day['weekday'],
                    'is_rest' => $isRest,
                    // Rest days carry no hours at all — matches the DB's is_rest XOR
                    // hours CHECK exactly, regardless of stray nulls the request let through.
                    'start_minute' => $isRest ? null : $day['start_minute'],
                    'end_minute' => $isRest ? null : $day['end_minute'],
                    'break_minutes' => $isRest ? null : $day['break_minutes'],
                ]);
            }

            return $template->load('days');
        });
    }
}
