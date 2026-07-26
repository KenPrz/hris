<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleAssignment> */
final class ScheduleAssignmentFactory extends Factory
{
    protected $model = ScheduleAssignment::class;

    /**
     * An employee-level assignment by default (the common case). Assign to a whole
     * department instead with `->for(Department::factory(), 'department')` and a null
     * employee_id — the resolver reads whichever is set.
     */
    public function definition(): array
    {
        return [
            'shift_template_id' => ShiftTemplate::factory(),
            'employee_id' => Employee::factory(),
            'department_id' => null,
            'effective_from' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }
}
