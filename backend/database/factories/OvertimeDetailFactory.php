<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeDetail>
 */
final class OvertimeDetailFactory extends Factory
{
    protected $model = OvertimeDetail::class;

    public function definition(): array
    {
        return [
            'request_id' => Request::factory(),
            'date' => $this->faker->date(),
            'minutes' => $this->faker->numberBetween(30, 240),
        ];
    }
}
