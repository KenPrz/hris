<?php

declare(strict_types=1);

use App\Domain\Time\NightDiffSplitter;
use App\Domain\Time\WorkInterval;

it('finds no night minutes in an ordinary day shift', function (): void {
    // 08:00 -> 17:00
    $split = NightDiffSplitter::split(WorkInterval::of(480, 1020));

    expect($split->inside->value)->toBe(0)
        ->and($split->outside->value)->toBe(540);
});

it('splits an evening shift that runs into the night window', function (): void {
    // 18:00 -> 23:30. The window opens at 22:00, so 90 minutes are night.
    $split = NightDiffSplitter::split(WorkInterval::of(1080, 1410));

    expect($split->inside->value)->toBe(90)
        ->and($split->outside->value)->toBe(240)
        ->and($split->total()->value)->toBe(330);
});

it('splits a night shift that crosses midnight', function (): void {
    // 22:00 -> 06:00, entirely inside the window.
    $split = NightDiffSplitter::split(WorkInterval::of(1320, 1800));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(0);
});

it('splits a shift ending after the window closes', function (): void {
    // 02:00 -> 10:00. Night runs 02:00-06:00 (240 min), day 06:00-10:00 (240 min).
    $split = NightDiffSplitter::split(WorkInterval::of(120, 600));

    expect($split->inside->value)->toBe(240)
        ->and($split->outside->value)->toBe(240);
});

it('splits a shift spanning both night bands of one business day', function (): void {
    // 03:00 -> 23:00 — an unrealistic 20-hour span, but it proves both bands are found:
    // 03:00-06:00 (180) plus 22:00-23:00 (60) = 240 night minutes.
    $split = NightDiffSplitter::split(WorkInterval::of(180, 1380));

    expect($split->inside->value)->toBe(240)
        ->and($split->outside->value)->toBe(960)
        ->and($split->total()->value)->toBe(1200);
});

it('handles a shift that starts and ends inside the same night band', function (): void {
    // 23:00 -> 01:00
    $split = NightDiffSplitter::split(WorkInterval::of(1380, 1500));

    expect($split->inside->value)->toBe(120)
        ->and($split->outside->value)->toBe(0);
});

it('always accounts for every worked minute exactly once', function (): void {
    // The property that matters: inside + outside == the interval's own duration.
    foreach ([[480, 1020], [1080, 1410], [1320, 1800], [120, 600], [180, 1380], [1380, 1500], [0, 1440]] as [$start, $end]) {
        $interval = WorkInterval::of($start, $end);
        $split = NightDiffSplitter::split($interval);

        expect($split->total()->value)->toBe($interval->duration()->value);
    }
});
