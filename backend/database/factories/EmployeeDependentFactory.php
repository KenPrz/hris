<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeDependent> */
final class EmployeeDependentFactory extends Factory
{
    protected $model = EmployeeDependent::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'name' => $this->faker->name(),
            'relationship_id' => Relationship::factory(),
            'birth_date' => $this->faker->date(),
        ];
    }
}
