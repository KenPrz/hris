<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pay\DayType;
use App\Models\PayRule;
use App\Models\PayRuleDayRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayRuleDayRate> */
final class PayRuleDayRateFactory extends Factory
{
    protected $model = PayRuleDayRate::class;

    public function definition(): array
    {
        return [
            'pay_rule_id' => PayRule::factory(),
            'day_type' => DayType::Ordinary,
            'worked_bp' => 10000,
            'worked_rest_bp' => 13000,
            'unworked_bp' => 0,
        ];
    }
}
