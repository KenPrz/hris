<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use App\Domain\Requests\RequestType;
use App\Models\Request;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * The calendar date(s) a request's effect would change — the one fact both the CloseCutoff
 * exception gate and the ApproveRequest cutoff refusal need. Leave and overtime carry their
 * dates explicitly; an attendance adjustment's date is the office-timezone calendar date of
 * the punch it adds or annuls.
 *
 * KNOWN LIMITATION: for an adjustment this is the punch's office-tz CALENDAR date, which for
 * a cross-midnight shift can differ by a day from the BUSINESS date its summary is keyed by.
 * Safe for the close gate (over-inclusion only), imprecise only for the approval refusal of a
 * cross-midnight punch within a day of a period boundary. Precise business-date attribution
 * for adjustments is deferred.
 */
final class RequestAffectedDates
{
    private function __construct() {}

    /** @return array<int, string> ascending, unique YYYY-MM-DD */
    public static function for(Request $request): array
    {
        $dates = match ($request->type) {
            RequestType::Overtime => [$request->overtimeDetail?->date?->toDateString()],
            RequestType::Leave => self::leaveDates($request),
            RequestType::AttendanceAdjustment => [self::adjustmentDate($request)],
        };

        $dates = array_values(array_unique(array_filter($dates)));
        sort($dates);

        return $dates;
    }

    /** @return array<int, string> */
    private static function leaveDates(Request $request): array
    {
        $detail = $request->leaveDetail;
        if ($detail === null) {
            return [];
        }

        return collect(CarbonPeriod::create($detail->start_date, $detail->end_date))
            ->map(fn ($d): string => $d->toDateString())
            ->all();
    }

    private static function adjustmentDate(Request $request): ?string
    {
        $detail = $request->attendanceAdjustmentDetail;
        if ($detail === null) {
            return null;
        }

        // add carries punched_at directly; void/amend point at the target log's punched_at.
        $punchedAt = $detail->punched_at ?? $detail->targetLog?->punched_at;
        if ($punchedAt === null) {
            return null;
        }

        $timezone = $request->employee?->currentOffice?->timezone ?? 'UTC';

        return Carbon::parse($punchedAt)->setTimezone($timezone)->toDateString();
    }
}
