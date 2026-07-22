<?php

declare(strict_types=1);

use App\Domain\Time\PunchPairer;

it('pairs a plain two-punch day', function (): void {
    // 08:00 -> 17:00
    $paired = PunchPairer::pair([480, 1020]);

    expect($paired->intervals)->toHaveCount(1)
        ->and($paired->intervals[0]->startMinute)->toBe(480)
        ->and($paired->intervals[0]->endMinute)->toBe(1020)
        ->and($paired->hasUnpaired())->toBeFalse()
        ->and($paired->totalWorked()->value)->toBe(540);
});

it('pairs a four-punch day with an explicit meal break', function (): void {
    // 08:00 -> 12:00, back 13:00 -> 17:00. Offices on the explicit policy punch out
    // for lunch, so the break simply is not inside any interval.
    $paired = PunchPairer::pair([480, 720, 780, 1020]);

    expect($paired->intervals)->toHaveCount(2)
        ->and($paired->totalWorked()->value)->toBe(480)
        ->and($paired->hasUnpaired())->toBeFalse();
});

it('pairs a night shift that crosses midnight', function (): void {
    // 22:00 -> 06:00 the next morning, as minutes from the business day's start.
    $paired = PunchPairer::pair([1320, 1800]);

    expect($paired->totalWorked()->value)->toBe(480);
});

it('reports an odd punch count as unpaired rather than guessing', function (): void {
    // A punch-in with no punch-out. The day computes as zero paid hours and is flagged
    // incomplete; the employee files an adjustment (M5). Never auto-close it here.
    $paired = PunchPairer::pair([480, 720, 780]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->unpairedMinute)->toBe(780)
        ->and($paired->intervals)->toHaveCount(1)
        ->and($paired->totalWorked()->value)->toBe(240);
});

it('treats a lone punch as entirely unpaired', function (): void {
    $paired = PunchPairer::pair([480]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->unpairedMinute)->toBe(480)
        ->and($paired->intervals)->toBeEmpty()
        ->and($paired->totalWorked()->value)->toBe(0);
});

it('handles a day with no punches at all', function (): void {
    $paired = PunchPairer::pair([]);

    expect($paired->hasUnpaired())->toBeFalse()
        ->and($paired->intervals)->toBeEmpty()
        ->and($paired->totalWorked()->value)->toBe(0);
});

it('refuses punches that are not in ascending order', function (): void {
    // Out-of-order punches mean the caller sorted wrong or the data is corrupt.
    // Sorting them here would paper over that silently.
    expect(fn () => PunchPairer::pair([720, 480]))
        ->toThrow(InvalidArgumentException::class, 'ascending order');
});

it('refuses two punches at the same minute', function (): void {
    // A zero-length interval is a double-punch, not a shift.
    expect(fn () => PunchPairer::pair([480, 480]))
        ->toThrow(InvalidArgumentException::class, 'ascending order');
});

it('refuses a negative punch minute', function (): void {
    expect(fn () => PunchPairer::pair([-5, 480]))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});
