<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffCalendar;

it('returns the first-half window for a date on or before the 15th', function (): void {
    expect(CutoffCalendar::windowFor('2026-07-01'))->toBe(['start' => '2026-07-01', 'end' => '2026-07-15']);
    expect(CutoffCalendar::windowFor('2026-07-15'))->toBe(['start' => '2026-07-01', 'end' => '2026-07-15']);
});

it('returns the second-half window to end-of-month for a date on or after the 16th', function (): void {
    expect(CutoffCalendar::windowFor('2026-07-16'))->toBe(['start' => '2026-07-16', 'end' => '2026-07-31']);
    expect(CutoffCalendar::windowFor('2026-07-31'))->toBe(['start' => '2026-07-16', 'end' => '2026-07-31']);
});

it('resolves end-of-month correctly for 30-day months and February', function (): void {
    expect(CutoffCalendar::windowFor('2026-06-20'))->toBe(['start' => '2026-06-16', 'end' => '2026-06-30']);
    expect(CutoffCalendar::windowFor('2026-02-20'))->toBe(['start' => '2026-02-16', 'end' => '2026-02-28']); // 2026 not a leap year
    expect(CutoffCalendar::windowFor('2028-02-20'))->toBe(['start' => '2028-02-16', 'end' => '2028-02-29']); // 2028 leap year
});

it('recognises only the 1st and 16th as valid period starts', function (): void {
    expect(CutoffCalendar::isValidStart('2026-07-01'))->toBeTrue();
    expect(CutoffCalendar::isValidStart('2026-07-16'))->toBeTrue();
    expect(CutoffCalendar::isValidStart('2026-07-02'))->toBeFalse();
    expect(CutoffCalendar::isValidStart('2026-07-15'))->toBeFalse();
});
