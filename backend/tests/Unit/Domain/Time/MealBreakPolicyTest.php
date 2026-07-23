<?php

declare(strict_types=1);

use App\Domain\Time\MealBreakPolicy;
use App\Domain\Time\Minutes;

it('deducts a fixed break once the threshold is passed, under the assumed policy', function (): void {
    // Art. 83's 60-minute unpaid meal break, applied to any span over 5 hours.
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 300);

    expect($policy->netWorked(Minutes::of(540))->value)->toBe(480)
        ->and($policy->netWorked(Minutes::of(301))->value)->toBe(241);
});

it('deducts nothing from a short span under the assumed policy', function (): void {
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 300);

    expect($policy->netWorked(Minutes::of(300))->value)->toBe(300)
        ->and($policy->netWorked(Minutes::of(240))->value)->toBe(240)
        ->and($policy->netWorked(Minutes::zero())->value)->toBe(0);
});

it('never drives a span negative under the assumed policy', function (): void {
    // A threshold shorter than the break would otherwise produce a negative duration.
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 30);

    expect($policy->netWorked(Minutes::of(45))->value)->toBe(0);
});

it('deducts nothing under the explicit policy, because the break was punched out', function (): void {
    // Offices on the explicit policy have employees clock out for lunch, so the break
    // is already absent from the paired intervals. Deducting again would double-count.
    $policy = MealBreakPolicy::explicit();

    expect($policy->netWorked(Minutes::of(480))->value)->toBe(480)
        ->and($policy->netWorked(Minutes::of(540))->value)->toBe(540);
});

it('refuses nonsensical assumed parameters', function (): void {
    expect(fn () => MealBreakPolicy::assumed(-1, 300))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => MealBreakPolicy::assumed(60, -1))
        ->toThrow(InvalidArgumentException::class);
});
