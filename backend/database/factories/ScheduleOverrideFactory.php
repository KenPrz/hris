<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleOverride> */
final class ScheduleOverrideFactory extends Factory
{
    protected $model = ScheduleOverride::class;

    /** A working-day override by default (08:00–18:00). Use `->rest()` for a rest-day one. */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => $this->faker->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'is_rest' => false,
            'start_minute' => 480,  // 08:00
            'end_minute' => 1080,   // 18:00
            'break_minutes' => 60,
            'note' => null,
            'created_by' => User::factory(),
        ];
    }

    /** A rest-day override: no hours, minute columns null (the DB CHECK requires the XOR). */
    public function rest(): static
    {
        return $this->state(fn (): array => [
            'is_rest' => true,
            'start_minute' => null,
            'end_minute' => null,
            'break_minutes' => null,
        ]);
    }

    /** A cross-midnight night shift, 22:00–06:00 (end expressed as 06:00 + 1440). */
    public function nightShift(): static
    {
        return $this->state(fn (): array => [
            'is_rest' => false,
            'start_minute' => 1320,  // 22:00
            'end_minute' => 1800,    // 06:00 the next day
            'break_minutes' => 60,
        ]);
    }
}
