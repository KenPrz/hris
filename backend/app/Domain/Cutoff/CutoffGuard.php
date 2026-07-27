<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use App\Exceptions\Domain\CutoffLocked;
use App\Models\CutoffPeriod;
use App\Models\Request;

/**
 * Refuses an approval whose effect would change a day in a CLOSED cutoff period. Called by
 * ApproveRequest on the final hop, under the affected employee's row lock — so the closed-
 * period read races correctly against a concurrent CloseCutoff (both hold that lock).
 */
final class CutoffGuard
{
    private function __construct() {}

    public static function assertOpen(Request $request): void
    {
        $officeId = $request->employee?->current_office_id;
        if ($officeId === null) {
            return;
        }

        foreach (RequestAffectedDates::for($request) as $date) {
            $closed = CutoffPeriod::query()
                ->where('office_id', $officeId)
                ->where('state', 'closed')
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists();

            if ($closed) {
                throw new CutoffLocked($date);
            }
        }
    }
}
