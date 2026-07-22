<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Pay\BasisPoints;

it('expresses a multiplier as an integer', function (): void {
    expect(BasisPoints::ONE)->toBe(10000)
        ->and(BasisPoints::of(20000)->value)->toBe(20000)
        ->and(BasisPoints::one()->value)->toBe(10000);
});

it('refuses a negative multiplier', function (): void {
    expect(fn () => BasisPoints::of(-1))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('composes exactly for the DOLE compounding chain', function (): void {
    // 200% regular holiday x 130% rest day x 130% overtime x 110% night differential.
    // Every step lands on a whole basis point, so nothing is lost to rounding.
    $composed = BasisPoints::of(20000)      // regular holiday
        ->times(BasisPoints::of(13000))     // on a rest day  -> 260%
        ->times(BasisPoints::of(13000))     // overtime       -> 338%
        ->times(BasisPoints::of(11000));    // night diff     -> 371.8%

    expect($composed->value)->toBe(37180);
});

it('composes step by step to the published DOLE figures', function (): void {
    expect(BasisPoints::of(20000)->times(BasisPoints::of(13000))->value)->toBe(26000)
        ->and(BasisPoints::of(26000)->times(BasisPoints::of(13000))->value)->toBe(33800)
        ->and(BasisPoints::of(13000)->times(BasisPoints::of(13000))->value)->toBe(16900);
});

it('is multiplicatively neutral at ONE', function (): void {
    expect(BasisPoints::of(13000)->times(BasisPoints::one())->value)->toBe(13000)
        ->and(BasisPoints::one()->times(BasisPoints::of(13000))->value)->toBe(13000);
});

it('rounds a composition half away from zero when it cannot be exact', function (): void {
    // These two cases have small remainders and round down via truncation.
    // 10001 x 10001 / 10000 = 10002.0001 -> 10002
    expect(BasisPoints::of(10001)->times(BasisPoints::of(10001))->value)->toBe(10002);
    // 10005 x 10001 / 10000 = 10006.0005 -> 10006
    expect(BasisPoints::of(10005)->times(BasisPoints::of(10001))->value)->toBe(10006);

    // These two cases force the round-up branch: remainder >= denominator/2
    // 10001 x 15000 = 150,015,000; intdiv 15001; remainder 5000; 5000*2 >= 10000 -> rounds to 15002
    expect(BasisPoints::of(10001)->times(BasisPoints::of(15000))->value)->toBe(15002);
    // 10009 x 11000 = 110,099,000; intdiv 11009; remainder 9000; 9000*2 >= 10000 -> rounds to 11010
    expect(BasisPoints::of(10009)->times(BasisPoints::of(11000))->value)->toBe(11010);
});

it('applies to money through the one rounding primitive', function (): void {
    // A daily rate of PHP 1,000.00 at 260% is PHP 2,600.00.
    expect(BasisPoints::of(26000)->applyTo(Money::fromCents(100000))->cents)->toBe(260000)
        ->and(BasisPoints::one()->applyTo(Money::fromCents(12345))->cents)->toBe(12345)
        // 37180 bp of 1 centavo is 3.718c, which rounds to 4c.
        ->and(BasisPoints::of(37180)->applyTo(Money::fromCents(1))->cents)->toBe(4);
});

it('renders a human-readable percent for reports and payslips', function (): void {
    expect(BasisPoints::of(10000)->toPercentString())->toBe('100%')
        ->and(BasisPoints::of(26000)->toPercentString())->toBe('260%')
        ->and(BasisPoints::of(37180)->toPercentString())->toBe('371.8%')
        ->and(BasisPoints::of(12525)->toPercentString())->toBe('125.25%');
});

it('compares by value', function (): void {
    expect(BasisPoints::of(13000)->equals(BasisPoints::of(13000)))->toBeTrue()
        ->and(BasisPoints::of(13000)->equals(BasisPoints::of(15000)))->toBeFalse();
});
