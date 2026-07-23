<?php

declare(strict_types=1);

use App\Domain\Pay\BasisPoints;
use App\Domain\Pay\DayType;
use App\Domain\Pay\PayMultiplier;

/*
| The DOLE premium-pay matrix, pinned cell by cell. Every number here traces to the
| Labor Code as amended (Arts. 86-94) and the DOLE handbook's worked examples.
|
| Read this file as the specification. If a rate changes by advisory, the change lands
| in the pay_rules table (M4), not here — these are the statutory floors the engine
| validates configured rates against.
*/

// base rate for time worked ---------------------------------------------------

dataset('worked base rates', [
    'ordinary day' => [DayType::Ordinary, false, 10000],
    'ordinary day on a rest day' => [DayType::Ordinary, true, 13000],
    'special working day' => [DayType::SpecialWorking, false, 10000],
    'special working day on a rest day' => [DayType::SpecialWorking, true, 13000],
    'special non-working day' => [DayType::SpecialNonWorking, false, 13000],
    'special non-working day on a rest day' => [DayType::SpecialNonWorking, true, 15000],
    'regular holiday' => [DayType::RegularHoliday, false, 20000],
    'regular holiday on a rest day' => [DayType::RegularHoliday, true, 26000],
    'double regular holiday' => [DayType::DoubleRegularHoliday, false, 30000],
    'double regular holiday on a rest day' => [DayType::DoubleRegularHoliday, true, 39000],
]);

it('pays the statutory base rate for time worked', function (DayType $type, bool $restDay, int $expected): void {
    expect(PayMultiplier::forWorkedTime($type, $restDay, false, false, false)->value)->toBe($expected);
})->with('worked base rates');

it('does not derive the special non-working rest-day rate by formula', function (): void {
    // 130% x 130% would be 169%. DOLE specifies a flat 150%. This single cell is why
    // the rest-day adjustment is a lookup table and not a multiplication.
    expect(PayMultiplier::forWorkedTime(DayType::SpecialNonWorking, true, false, false, false)->value)
        ->toBe(15000)
        ->and(BasisPoints::of(13000)->times(BasisPoints::of(13000))->value)->toBe(16900);
});

// overtime --------------------------------------------------------------------

dataset('overtime rates', [
    'ordinary day' => [DayType::Ordinary, false, 12500],
    'ordinary day on a rest day' => [DayType::Ordinary, true, 16900],
    'special working day' => [DayType::SpecialWorking, false, 12500],
    'special working day on a rest day' => [DayType::SpecialWorking, true, 16900],
    'special non-working day' => [DayType::SpecialNonWorking, false, 16900],
    'special non-working day on a rest day' => [DayType::SpecialNonWorking, true, 19500],
    'regular holiday' => [DayType::RegularHoliday, false, 26000],
    'regular holiday on a rest day' => [DayType::RegularHoliday, true, 33800],
    'double regular holiday' => [DayType::DoubleRegularHoliday, false, 39000],
    'double regular holiday on a rest day' => [DayType::DoubleRegularHoliday, true, 50700],
]);

it('pays overtime at +25% on an ordinary day and +30% everywhere else', function (DayType $type, bool $restDay, int $expected): void {
    expect(PayMultiplier::forWorkedTime($type, $restDay, true, false, false)->value)->toBe($expected);
})->with('overtime rates');

// night differential ----------------------------------------------------------

it('compounds night differential on the already-premium rate, not on base pay', function (): void {
    // The mistake this pins: 200% + 10% = 210% is wrong. It is 200% x 110% = 220%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, false, false, true, false)->value)
        ->toBe(22000);

    // Holiday overtime at 2am: 200% x 130% x 110%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, false, true, true, false)->value)
        ->toBe(28600);

    // An ordinary night shift hour.
    expect(PayMultiplier::forWorkedTime(DayType::Ordinary, false, false, true, false)->value)
        ->toBe(11000);
});

it('reaches 371.8% for the worst-case hour in the matrix', function (): void {
    // Regular holiday, falling on a rest day, in overtime, inside the night window:
    // 200% x 130% x 130% x 110%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, true, true, true, false)->value)
        ->toBe(37180);
});

// Art. 82 --------------------------------------------------------------------

it('pays an Art. 82-exempt employee flat, whatever the day', function (): void {
    // Managerial employees and field personnel are outside Art. 82's coverage: no
    // overtime, no night differential, no holiday premium. Every combination collapses.
    foreach (DayType::cases() as $type) {
        foreach ([true, false] as $restDay) {
            foreach ([true, false] as $overtime) {
                foreach ([true, false] as $night) {
                    expect(PayMultiplier::forWorkedTime($type, $restDay, $overtime, $night, true)->value)
                        ->toBe(10000);
                }
            }
        }
    }
});

it('pays an Art. 82-exempt employee nothing extra for an unworked holiday', function (): void {
    expect(PayMultiplier::forUnworkedDay(DayType::RegularHoliday, true)->value)->toBe(0)
        ->and(PayMultiplier::forUnworkedDay(DayType::DoubleRegularHoliday, true)->value)->toBe(0);
});

// unworked days ---------------------------------------------------------------

dataset('unworked day rates', [
    'ordinary day' => [DayType::Ordinary, 0],
    'special working day' => [DayType::SpecialWorking, 0],
    'special non-working day' => [DayType::SpecialNonWorking, 0],
    'regular holiday' => [DayType::RegularHoliday, 10000],
    'double regular holiday' => [DayType::DoubleRegularHoliday, 20000],
]);

it('pays an unworked regular holiday and nothing for an unworked special day', function (DayType $type, int $expected): void {
    // "No work, no pay" applies to special non-working days absent a more generous
    // company policy; a regular holiday is paid whether worked or not.
    expect(PayMultiplier::forUnworkedDay($type, false)->value)->toBe($expected);
})->with('unworked day rates');

// completeness ----------------------------------------------------------------

it('covers every day type, so a new one cannot be added without a rate', function (): void {
    foreach (DayType::cases() as $type) {
        expect(PayMultiplier::forWorkedTime($type, false, false, false, false)->value)
            ->toBeGreaterThanOrEqual(10000)
            ->and(PayMultiplier::forUnworkedDay($type, false)->value)->toBeGreaterThanOrEqual(0);
    }
});
