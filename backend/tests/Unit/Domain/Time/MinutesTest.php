<?php

declare(strict_types=1);

use App\Domain\Time\Minutes;

it('holds a whole number of minutes', function (): void {
    expect(Minutes::of(440)->value)->toBe(440)
        ->and(Minutes::zero()->value)->toBe(0);
});

it('refuses a negative duration', function (): void {
    // A negative duration is a bug, not a value. Undertime is its own non-negative
    // magnitude, recorded separately — see OvertimeThreshold.
    expect(fn () => Minutes::of(-1))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('adds and subtracts', function (): void {
    expect(Minutes::of(480)->plus(Minutes::of(90))->value)->toBe(570)
        ->and(Minutes::of(480)->minus(Minutes::of(90))->value)->toBe(390);
});

it('refuses a subtraction that would go negative rather than clamping', function (): void {
    // Clamping to zero would silently swallow the bug that produced the inversion.
    expect(fn () => Minutes::of(90)->minus(Minutes::of(480)))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('sums a list', function (): void {
    expect(Minutes::sum([Minutes::of(60), Minutes::of(30), Minutes::of(15)])->value)->toBe(105)
        ->and(Minutes::sum([])->value)->toBe(0);
});

it('compares', function (): void {
    $short = Minutes::of(60);
    $long = Minutes::of(120);

    expect($short->lessThan($long))->toBeTrue()
        ->and($long->greaterThan($short))->toBeTrue()
        ->and($short->equals(Minutes::of(60)))->toBeTrue()
        ->and($short->compareTo($long))->toBe(-1)
        ->and($short->min($long)->value)->toBe(60)
        ->and($short->max($long)->value)->toBe(120)
        ->and(Minutes::zero()->isZero())->toBeTrue();
});
