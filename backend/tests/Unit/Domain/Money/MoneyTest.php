<?php

declare(strict_types=1);

use App\Domain\Money\Money;

it('holds integer centavos', function (): void {
    expect(Money::fromCents(123456)->cents)->toBe(123456)
        ->and(Money::zero()->cents)->toBe(0);
});

it('parses a decimal string with string arithmetic, never a float cast', function (): void {
    // (int) (float) '1.15' * 100 famously yields 114. This is why parse() exists.
    expect(Money::parse('1.15')->cents)->toBe(115)
        ->and(Money::parse('12.34')->cents)->toBe(1234)
        ->and(Money::parse('7')->cents)->toBe(700)
        ->and(Money::parse('-3.05')->cents)->toBe(-305);
});

it('rejects a third decimal place rather than rounding it away', function (): void {
    // Silently discarding a digit is losing money quietly.
    expect(fn () => Money::parse('1.234'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('abc'))->toThrow(InvalidArgumentException::class);
});

it('adds, subtracts, multiplies and negates', function (): void {
    expect(Money::fromCents(1000)->plus(Money::fromCents(250))->cents)->toBe(1250)
        ->and(Money::fromCents(1000)->minus(Money::fromCents(250))->cents)->toBe(750)
        ->and(Money::fromCents(1000)->multipliedBy(3)->cents)->toBe(3000)
        ->and(Money::fromCents(-500)->negated()->cents)->toBe(500)
        ->and(Money::fromCents(-500)->absolute()->cents)->toBe(500);
});

it('rounds half away from zero in fraction(), the one rounding primitive', function (): void {
    // 0.5c rounds up to 1c rather than vanishing — and symmetrically for negatives.
    expect(Money::fromCents(1)->fraction(1, 2)->cents)->toBe(1)
        ->and(Money::fromCents(-1)->fraction(1, 2)->cents)->toBe(-1)
        ->and(Money::fromCents(3)->fraction(1, 2)->cents)->toBe(2)
        ->and(Money::fromCents(10000)->fraction(13, 10)->cents)->toBe(13000);
});

it('refuses division by zero', function (): void {
    expect(fn () => Money::fromCents(100)->fraction(1, 0))
        ->toThrow(InvalidArgumentException::class, 'Division by zero');
});

it('allocates so the parts always sum exactly back to the whole', function (): void {
    $parts = Money::fromCents(1000)->allocate(3);

    expect(array_map(fn (Money $m): int => $m->cents, $parts))->toBe([334, 333, 333])
        ->and(Money::sum($parts)->cents)->toBe(1000);
});

it('allocates by ratios without inventing or destroying centavos', function (): void {
    $parts = Money::fromCents(1000)->allocateByRatios([1, 1, 3]);

    expect(Money::sum($parts)->cents)->toBe(1000)
        ->and(count($parts))->toBe(3);
});

it('rejects nonsensical allocations', function (): void {
    expect(fn () => Money::fromCents(100)->allocate(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([0, 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([1, -1]))->toThrow(InvalidArgumentException::class);
});

it('fails with a reason on overflow rather than silently promoting to float', function (): void {
    // PHP promotes integer overflow to float — the exact thing this class prevents.
    expect(fn () => Money::fromCents(PHP_INT_MAX)->fraction(1000, 1))
        ->toThrow(InvalidArgumentException::class, 'integer overflow');
});

it('compares', function (): void {
    $small = Money::fromCents(100);
    $large = Money::fromCents(500);

    expect($small->lessThan($large))->toBeTrue()
        ->and($large->greaterThan($small))->toBeTrue()
        ->and($large->greaterThanOrEqual(Money::fromCents(500)))->toBeTrue()
        ->and($small->equals(Money::fromCents(100)))->toBeTrue()
        ->and($small->compareTo($large))->toBe(-1)
        ->and($small->min($large)->cents)->toBe(100)
        ->and($small->max($large)->cents)->toBe(500)
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($small->isPositive())->toBeTrue()
        ->and($small->negated()->isNegative())->toBeTrue();
});

it('has no float constructor at all', function (): void {
    // Not discouraged — absent. Under strict_types this is a TypeError, and that is
    // the point: the mistake is unrepresentable rather than merely frowned upon.
    expect(fn () => Money::fromCents(1.5)) // @phpstan-ignore-line
        ->toThrow(TypeError::class);
});

it('sums a list', function (): void {
    expect(Money::sum([Money::fromCents(100), Money::fromCents(250)])->cents)->toBe(350)
        ->and(Money::sum([])->cents)->toBe(0);
});
