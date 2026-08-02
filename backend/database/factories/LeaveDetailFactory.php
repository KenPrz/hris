<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveDetail> */
final class LeaveDetailFactory extends Factory
{
    protected $model = LeaveDetail::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('now', '+1 month');
        $end = (clone $start)->modify('+'.$this->faker->numberBetween(0, 4).' days');

        return [
            'request_id' => Request::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'day_part' => 'full',
            'amount_minutes' => 480,
            // The per-day quantity the compute engine prices a leave day at, and what
            // amount_minutes is a multiple of. A one-day 'full' leave, so the two match.
            'minutes_per_day' => 480,
        ];
    }

    /** Half a standard 480-minute day, with day_part and both minute figures kept consistent. */
    public function halfDay(): self
    {
        return $this->state(fn (): array => [
            'day_part' => 'half',
            'amount_minutes' => 240,
            'minutes_per_day' => 240,
        ]);
    }
}
