<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Domain\Pay\PayRates;
use App\Models\PayRule;
use App\Models\PayRuleDayRate;
use App\Support\PayRatesFactory;

/*
| PayRates is the pure matrix PayMultiplier reads instead of its own hardcoded
| constants. Two constructors sit outside the domain — PayRatesFactory::statutory()
| reads config('hris.pay_floors'); PayRatesFactory::fromVersion() reads a PayRule model
| and its dayRates — because the domain layer never reads configuration
| (tests/Arch/ConventionsTest.php). PayRates itself takes its five arguments as plain
| arrays/ints and knows nothing about where they came from.
|
| tests/Unit is deliberately unbooted (see tests/Pest.php), but this file needs
| config('hris.pay_floors') as its statutory fixture, so it opts back into the booted
| TestCase the same way StatutoryFloorTest does.
*/
uses(Tests\TestCase::class);

it('builds the statutory rates from config(\'hris.pay_floors\'), values equal', function (): void {
    $floors = config('hris.pay_floors');
    $rates = PayRatesFactory::statutory();

    foreach (DayType::cases() as $type) {
        expect($rates->worked($type, false))->toBe($floors['worked'][$type->value][0])
            ->and($rates->worked($type, true))->toBe($floors['worked'][$type->value][1])
            ->and($rates->unworked($type))->toBe($floors['unworked'][$type->value]);
    }

    expect($rates->overtimeOrdinary())->toBe($floors['overtime_ordinary'])
        ->and($rates->overtimePremium())->toBe($floors['overtime_premium'])
        ->and($rates->nightDiff())->toBe($floors['night_diff']);
});

it('builds rates from a PayRule version\'s scalars and its five dayRates', function (): void {
    $payRule = new PayRule([
        'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000,
    ]);

    $payRule->setRelation('dayRates', collect([
        new PayRuleDayRate(['day_type' => DayType::Ordinary, 'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]),
        new PayRuleDayRate(['day_type' => DayType::SpecialWorking, 'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]),
        new PayRuleDayRate(['day_type' => DayType::SpecialNonWorking, 'worked_bp' => 13000, 'worked_rest_bp' => 15000, 'unworked_bp' => 0]),
        new PayRuleDayRate(['day_type' => DayType::RegularHoliday, 'worked_bp' => 20000, 'worked_rest_bp' => 26000, 'unworked_bp' => 10000]),
        new PayRuleDayRate(['day_type' => DayType::DoubleRegularHoliday, 'worked_bp' => 30000, 'worked_rest_bp' => 39000, 'unworked_bp' => 20000]),
    ]));

    $rates = PayRatesFactory::fromVersion($payRule);

    expect($rates)->toBeInstanceOf(PayRates::class)
        ->and($rates->worked(DayType::RegularHoliday, false))->toBe(20000)
        ->and($rates->worked(DayType::RegularHoliday, true))->toBe(26000)
        ->and($rates->unworked(DayType::RegularHoliday))->toBe(10000)
        ->and($rates->unworked(DayType::Ordinary))->toBe(0)
        ->and($rates->overtimeOrdinary())->toBe(12500)
        ->and($rates->overtimePremium())->toBe(13000)
        ->and($rates->nightDiff())->toBe(11000);
});

it('is a plain, framework-agnostic holder once built', function (): void {
    $rates = new PayRates(
        worked: [DayType::Ordinary->value => [10000, 13000]],
        unworked: [DayType::Ordinary->value => 0],
        overtimeOrdinaryBp: 12500,
        overtimePremiumBp: 13000,
        nightDiffBp: 11000,
    );

    expect($rates->worked(DayType::Ordinary, false))->toBe(10000)
        ->and($rates->worked(DayType::Ordinary, true))->toBe(13000)
        ->and($rates->unworked(DayType::Ordinary))->toBe(0)
        ->and($rates->overtimeOrdinary())->toBe(12500)
        ->and($rates->overtimePremium())->toBe(13000)
        ->and($rates->nightDiff())->toBe(11000);
});
