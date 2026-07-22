<?php

declare(strict_types=1);

use App\Domain\Time\Minutes;
use App\Domain\Time\OvertimeThreshold;

it('reports a full day with no overtime', function (): void {
    $split = OvertimeThreshold::split(Minutes::of(480), Minutes::of(480));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(0);
});

it('splits hours beyond the scheduled day into overtime', function (): void {
    // 10h30m worked against an 8h schedule.
    $split = OvertimeThreshold::split(Minutes::of(630), Minutes::of(480));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(150);
});

it('reports no overtime and no negative regular time when a day is short', function (): void {
    $split = OvertimeThreshold::split(Minutes::of(300), Minutes::of(480));

    expect($split->inside->value)->toBe(300)
        ->and($split->outside->value)->toBe(0);
});

it('measures undertime as its own non-negative magnitude', function (): void {
    // Undertime is not negative overtime. It is a separate number on the payslip.
    expect(OvertimeThreshold::undertime(Minutes::of(300), Minutes::of(480))->value)->toBe(180)
        ->and(OvertimeThreshold::undertime(Minutes::of(480), Minutes::of(480))->value)->toBe(0)
        ->and(OvertimeThreshold::undertime(Minutes::of(630), Minutes::of(480))->value)->toBe(0);
});

it('handles a compressed workweek schedule', function (): void {
    // 4x10 compressed: a 10-hour scheduled day, so hour nine is regular, not overtime.
    $split = OvertimeThreshold::split(Minutes::of(660), Minutes::of(600));

    expect($split->inside->value)->toBe(600)
        ->and($split->outside->value)->toBe(60);
});

it('always accounts for every worked minute exactly once', function (): void {
    foreach ([[480, 480], [630, 480], [300, 480], [660, 600], [0, 480]] as [$worked, $scheduled]) {
        $split = OvertimeThreshold::split(Minutes::of($worked), Minutes::of($scheduled));

        expect($split->total()->value)->toBe($worked);
    }
});
