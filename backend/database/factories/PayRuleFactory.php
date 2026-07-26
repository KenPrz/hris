<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pay\DayType;
use App\Models\PayRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayRule> */
final class PayRuleFactory extends Factory
{
    protected $model = PayRule::class;

    public function definition(): array
    {
        $floors = config('hris.pay_floors');

        return [
            'effective_from' => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'overtime_ordinary_bp' => $floors['overtime_ordinary'],
            'overtime_premium_bp' => $floors['overtime_premium'],
            'night_diff_bp' => $floors['night_diff'],
            'note' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Attach the five day-rate rows at the statutory floor, so the version is complete
     * enough for PayRatesFactory/PayMultiplier to price a day against. A bare
     * `PayRule::factory()` is only the header row — use this when the compute path reads it.
     */
    public function withStatutoryFloorRates(): static
    {
        return $this->afterCreating(function (PayRule $payRule): void {
            $floors = config('hris.pay_floors');

            foreach (DayType::cases() as $dayType) {
                [$worked, $workedRest] = $floors['worked'][$dayType->value];

                $payRule->dayRates()->create([
                    'day_type' => $dayType->value,
                    'worked_bp' => $worked,
                    'worked_rest_bp' => $workedRest,
                    'unworked_bp' => $floors['unworked'][$dayType->value],
                ]);
            }
        });
    }
}
