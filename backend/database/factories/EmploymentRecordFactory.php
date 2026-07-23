<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmploymentRecord> */
final class EmploymentRecordFactory extends Factory
{
    protected $model = EmploymentRecord::class;

    public function definition(): array
    {
        $office = Office::factory()->create();

        return [
            'employee_id' => Employee::factory(),
            'effective_from' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'office_id' => $office->id,
            'department_id' => Department::factory()->for($office)->create()->id,
            'reports_to_id' => null,
            'employment_type' => 'regular',
            'is_art82_exempt' => false,
            'base_rate_cents' => 61000, // ~PHP 610/day, near the NCR minimum
        ];
    }
}
