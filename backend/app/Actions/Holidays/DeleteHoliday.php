<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one holiday row. The office-scope check (does the caller administer this
 * holiday's office?) already happened in the controller — this action trusts its input
 * and only writes. Spatie's LogsActivity on Holiday records the `deleted` event itself,
 * with the causer resolved from the authenticated guard automatically.
 */
final class DeleteHoliday
{
    public function execute(Holiday $holiday): void
    {
        DB::transaction(static function () use ($holiday): void {
            $holiday->delete();
        });
    }
}
