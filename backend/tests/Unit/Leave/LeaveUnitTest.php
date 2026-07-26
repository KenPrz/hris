<?php

declare(strict_types=1);

use App\Domain\Leave\LeaveUnit;

it('converts a day to minutes using minutes-per-leave-day', function (): void {
    expect(LeaveUnit::toMinutes(5, 'day', 480))->toBe(2400);
});

it('converts a half_shift to minutes as intdiv(mpld, 2)', function (): void {
    expect(LeaveUnit::toMinutes(1, 'half_shift', 480))->toBe(240);
});

it('converts hours to minutes', function (): void {
    expect(LeaveUnit::toMinutes(3, 'hour', 480))->toBe(180);
});

it('passes minutes through unchanged', function (): void {
    expect(LeaveUnit::toMinutes(90, 'minute', 480))->toBe(90);
});

it('throws on an unknown unit', function (): void {
    expect(fn () => LeaveUnit::toMinutes(1, 'fortnight', 480))
        ->toThrow(InvalidArgumentException::class);
});

it('throws on a non-positive amount', function (): void {
    expect(fn () => LeaveUnit::toMinutes(0, 'day', 480))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => LeaveUnit::toMinutes(-1, 'day', 480))
        ->toThrow(InvalidArgumentException::class);
});

it('decomposes minutes into days/hours/minutes for display', function (): void {
    expect(LeaveUnit::readable(2400, 480))->toBe(['days' => 5, 'hours' => 0, 'minutes' => 0]);
});

it('decomposes a partial day correctly', function (): void {
    expect(LeaveUnit::readable(555, 480))->toBe(['days' => 1, 'hours' => 1, 'minutes' => 15]);
});
