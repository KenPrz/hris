<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Office;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Office> */
final class OfficeFactory extends Factory
{
    protected $model = Office::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->city(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'timezone' => 'Asia/Manila',
        ];
    }
}
