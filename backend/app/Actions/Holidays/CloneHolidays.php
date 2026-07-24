<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Models\Holiday;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies one year of an office's holidays onto the same month/day of another year — an
 * editable draft the admin then adjusts, since the movable holidays (Eid, long-weekend
 * proclamations) don't repeat on a fixed date. The office-scope check already happened in
 * the controller; this action trusts its input and only writes.
 *
 * Re-runnable by design: any target date already occupied is skipped rather than
 * overwritten, so cloning the same year twice creates nothing the second time. The target
 * date is built from the source's month/day directly — never a +365-day shift, which would
 * land on the wrong day across a leap year — and a Feb 29 source with no Feb 29 in the
 * target year is skipped rather than sliding to Mar 1.
 */
final class CloneHolidays
{
    public function execute(CloneHolidaysInput $in): Collection
    {
        return DB::transaction(function () use ($in): Collection {
            $office = $in->office;

            $sourceHolidays = $office->holidays()
                ->whereYear('date', $in->fromYear)
                ->get();

            $occupiedDates = $office->holidays()
                ->whereYear('date', $in->toYear)
                ->pluck('date')
                ->map(static fn (mixed $date): string => $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (string) $date)
                ->all();

            $created = collect();

            foreach ($sourceHolidays as $holiday) {
                $month = (int) $holiday->date->format('n');
                $day = (int) $holiday->date->format('j');

                // Feb 29 with no Feb 29 in the target year (non-leap) — skip, never shift
                // to Mar 1. checkdate() rejects any other out-of-range month/day too.
                if (! checkdate($month, $day, $in->toYear)) {
                    continue;
                }

                $targetDate = sprintf('%04d-%02d-%02d', $in->toYear, $month, $day);

                if (in_array($targetDate, $occupiedDates, true)) {
                    continue;
                }

                $created->push(Holiday::query()->create([
                    'office_id' => $office->id,
                    'date' => $targetDate,
                    'day_type' => $holiday->day_type,
                    'name' => $holiday->name,
                ]));

                // Guard against two source rows landing on the same target date within
                // this same batch, not just against what was already in the database.
                $occupiedDates[] = $targetDate;
            }

            activity()
                ->causedBy($in->causer)
                ->performedOn($office)
                ->withProperties([
                    'from_year' => $in->fromYear,
                    'to_year' => $in->toYear,
                    'created' => $created->count(),
                ])
                ->log('cloned holidays');

            return $created;
        });
    }
}
