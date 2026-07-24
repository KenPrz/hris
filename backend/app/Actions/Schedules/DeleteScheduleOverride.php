<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\ScheduleOverride;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one schedule override row. The office-scope check (does the caller administer
 * this override's employee's office?) already happened in the controller — this action
 * trusts its input and only writes.
 */
final class DeleteScheduleOverride
{
    public function execute(ScheduleOverride $override): void
    {
        DB::transaction(static function () use ($override): void {
            $override->delete();
        });
    }
}
