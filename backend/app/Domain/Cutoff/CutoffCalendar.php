<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use Illuminate\Support\Carbon;

/**
 * The semi-monthly cutoff window rule: the 1st–15th and the 16th–end-of-month. Pure — no
 * DB, no models. Per-office custom schedules (weekly/monthly/arbitrary) are deferred; this
 * is the roadmap's stated default and the only rule M7a implements.
 */
final class CutoffCalendar
{
    private function __construct() {}

    /** @return array{start: string, end: string} the window (inclusive) containing $date. */
    public static function windowFor(string $date): array
    {
        $d = Carbon::parse($date);

        if ($d->day <= 15) {
            return ['start' => $d->copy()->startOfMonth()->toDateString(), 'end' => $d->copy()->day(15)->toDateString()];
        }

        return ['start' => $d->copy()->day(16)->toDateString(), 'end' => $d->copy()->endOfMonth()->toDateString()];
    }

    public static function isValidStart(string $date): bool
    {
        $day = Carbon::parse($date)->day;

        return $day === 1 || $day === 16;
    }
}
