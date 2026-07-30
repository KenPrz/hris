<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeIdentification> */
final class EmployeeIdentificationFactory extends Factory
{
    protected $model = EmployeeIdentification::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'category_id' => EmployeeIdentificationCategory::factory(),
            'number' => $this->faker->numerify('############'),
            'issued_on' => $this->faker->date(),
        ];
    }
}
