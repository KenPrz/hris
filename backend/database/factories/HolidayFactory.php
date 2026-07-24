<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pay\DayType;
use App\Models\Holiday;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
final class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'date' => $this->faker->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'day_type' => DayType::RegularHoliday,
            'name' => $this->faker->words(2, true),
        ];
    }
}
