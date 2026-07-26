<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\AffectedSummaries;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\ShiftTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Creates one shift template and its seven weekday rows for an office. The office-scope
 * check (is this office one the caller administers?) already happened in the controller —
 * this action trusts its input and only writes. Spatie's LogsActivity on ShiftTemplate
 * records the `created` event itself, with the causer resolved from the authenticated
 * guard automatically.
 *
 * After the write commits, enqueues an audited recompute (M5b Task 6) of every EXISTING
 * summary for an employee on this template. A brand-new template has no assignment
 * pointing at it yet, so AffectedSummaries::forShiftTemplate returns [] and this is
 * always a clean no-op here — wired anyway for uniformity with Update/DeleteShiftTemplate.
 */
final class CreateShiftTemplate
{
    public function execute(CreateShiftTemplateInput $in): ShiftTemplate
    {
        return DB::transaction(function () use ($in): ShiftTemplate {
            $template = ShiftTemplate::query()->create([
                'office_id' => $in->officeId,
                'name' => $in->name,
            ]);

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

            DB::afterCommit(function () use ($template): void {
                RecomputeRange::dispatch(
                    AffectedSummaries::forShiftTemplate($template->id),
                    RecomputeTrigger::ShiftTemplate,
                    $template->id,
                    "Shift template {$template->id} created for office {$template->office_id}",
                );
            });

            return $template->load('days');
        });
    }
}
