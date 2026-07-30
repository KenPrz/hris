<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeeIdentificationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeIdentificationCategory> */
final class EmployeeIdentificationCategoryFactory extends Factory
{
    protected $model = EmployeeIdentificationCategory::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('ID_?????'),
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
        ];
    }
}
