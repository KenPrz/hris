<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;

it('pins the statutory pay floors to the Labor Code minimums', function (): void {
    $floors = config('hris.pay_floors');

    expect($floors['worked'][DayType::Ordinary->value])->toBe([10000, 13000])
        ->and($floors['worked'][DayType::SpecialWorking->value])->toBe([10000, 13000])
        ->and($floors['worked'][DayType::SpecialNonWorking->value])->toBe([13000, 15000])
        ->and($floors['worked'][DayType::RegularHoliday->value])->toBe([20000, 26000])
        ->and($floors['worked'][DayType::DoubleRegularHoliday->value])->toBe([30000, 39000])
        ->and($floors['unworked'][DayType::Ordinary->value])->toBe(0)
        ->and($floors['unworked'][DayType::SpecialWorking->value])->toBe(0)
        ->and($floors['unworked'][DayType::SpecialNonWorking->value])->toBe(0)
        ->and($floors['unworked'][DayType::RegularHoliday->value])->toBe(10000)
        ->and($floors['unworked'][DayType::DoubleRegularHoliday->value])->toBe(20000)
        ->and($floors['overtime_ordinary'])->toBe(12500)
        ->and($floors['overtime_premium'])->toBe(13000)
        ->and($floors['night_diff'])->toBe(11000);
});
