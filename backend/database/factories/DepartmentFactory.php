<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => $this->faker->word(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
        ];
    }
}
