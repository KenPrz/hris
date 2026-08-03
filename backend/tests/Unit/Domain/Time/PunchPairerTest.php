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

it('reports punches that are not in ascending order as incomplete', function (): void {
    // Out-of-order punches mean the caller sorted wrong or the data is corrupt. Sorting them
    // here would paper over that silently — but throwing bricked the day (see below), so the
    // day is reported incomplete and reaches an HR admin through the exception gate instead.
    $paired = PunchPairer::pair([720, 480]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->intervals)->toBe([])
        ->and($paired->totalWorked()->value)->toBe(0);
});

it('reports two punches at the same minute as incomplete rather than throwing', function (): void {
    // A zero-length interval is a double-punch, not a shift. This used to throw — but by then
    // the punch was durable (compute runs in afterCommit), so the throw escaped as a 500 and
    // the day got no summary row at all: invisible to CloseCutoff's incomplete-day gate, so
    // the period closed with it worth zero, permanently.
    $paired = PunchPairer::pair([480, 480]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->intervals)->toBe([])
        ->and($paired->unpairedMinute)->toBe(480);
});

it('still pairs the good punches around a later collision as incomplete, not partially', function (): void {
    // All-or-nothing: a collision anywhere means the day's punch sequence is untrustworthy,
    // so nothing is priced from it. Pricing the clean leading pair would pay a day that an
    // HR admin has not yet reconciled.
    $paired = PunchPairer::pair([480, 720, 900, 900]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->intervals)->toBe([]);
});

it('refuses a negative punch minute', function (): void {
    expect(fn () => PunchPairer::pair([-5, 480]))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});
