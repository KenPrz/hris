<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pay\DayType;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyAttendanceSummary> */
final class DailyAttendanceSummaryFactory extends Factory
{
    protected $model = DailyAttendanceSummary::class;

    /**
     * A complete, computed ordinary working day by default. This factory writes a summary
     * row directly — the real system only ever produces one through ComputeDailySummary;
     * use it in tests that need a summary to exist, not to assert the engine's output.
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'office_id' => Office::factory(),
            'day_type' => DayType::Ordinary,
            'is_rest_day' => false,
            'scheduled_minutes' => 540,
            'is_art82_exempt' => false,
            'rule_version_id' => null,
            'worked_minutes' => 540,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'computed',
            'is_incomplete' => false,
            'computed_at' => now(),
        ];
    }

    /** A day whose period has been locked by a closed cutoff — the state a recompute skips. */
    public function locked(): static
    {
        return $this->state(fn (): array => ['status' => 'locked']);
    }

    /** An unpaired-punch day: zero worked, flagged incomplete, no priced lines. */
    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'worked_minutes' => 0,
            'is_incomplete' => true,
        ]);
    }
}
