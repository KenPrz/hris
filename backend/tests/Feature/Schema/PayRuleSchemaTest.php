<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Models\PayRule;
use App\Models\PayRuleDayRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function payRule(string $effectiveFrom = '2026-01-01'): PayRule
{
    return PayRule::create([
        'effective_from' => $effectiveFrom, 'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000, 'night_diff_bp' => 11000,
    ]);
}

it('stores a version with its day rates and casts day_type', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::RegularHoliday,
        'worked_bp' => 20000, 'worked_rest_bp' => 26000, 'unworked_bp' => 10000]);
    $rate = $rule->dayRates()->sole();
    expect($rate->day_type)->toBe(DayType::RegularHoliday)->and($rate->worked_bp)->toBe(20000);
});

it('rejects a second version on the same effective_from', function (): void {
    payRule('2026-01-01');
    expect(fn () => payRule('2026-01-01'))->toThrow(QueryException::class);
});

it('rejects a duplicate day_type within a version', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]);
    expect(fn () => PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]))->toThrow(QueryException::class);
});

it('rejects negative basis points', function (): void {
    expect(fn () => DB::table('pay_rules')->insert(['id' => Str::uuid7()->toString(),
        'effective_from' => '2026-02-01', 'overtime_ordinary_bp' => -1, 'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('rejects negative basis points on day rates', function (): void {
    $rule = payRule();
    expect(fn () => DB::table('pay_rule_day_rates')->insert(['id' => Str::uuid7()->toString(),
        'pay_rule_id' => $rule->id, 'day_type' => 'ordinary', 'worked_bp' => -1, 'worked_rest_bp' => 13000,
        'unworked_bp' => 0, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('rejects day_type values outside the enum', function (): void {
    $rule = payRule();
    expect(fn () => DB::table('pay_rule_day_rates')->insert(['id' => Str::uuid7()->toString(),
        'pay_rule_id' => $rule->id, 'day_type' => 'nonsense', 'worked_bp' => 10000, 'worked_rest_bp' => 13000,
        'unworked_bp' => 0, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('cascades day-rate deletion when a version is deleted', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]);
    $rule->delete();
    expect(PayRuleDayRate::count())->toBe(0);
});
