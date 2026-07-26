<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Office;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftTemplate> */
final class ShiftTemplateFactory extends Factory
{
    protected $model = ShiftTemplate::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => 'Standard Mon–Fri',
        ];
    }

    /**
     * Attach the seven weekday rows of the canonical Mon–Fri 08:00–18:00 (one-hour break,
     * weekends rest) shape — the same shape CompanySeeder and ScheduleResolver's tests use.
     * A bare `ShiftTemplate::factory()` has no days; use this when a resolvable template is
     * needed.
     */
    public function standardWeek(): static
    {
        return $this->afterCreating(function (ShiftTemplate $template): void {
            foreach (range(0, 6) as $weekday) {
                $isWeekend = $weekday >= 5; // Weekday::Saturday = 5, Weekday::Sunday = 6

                $template->days()->create([
                    'weekday' => $weekday,
                    'is_rest' => $isWeekend,
                    'start_minute' => $isWeekend ? null : 480,
                    'end_minute' => $isWeekend ? null : 1080,
                    'break_minutes' => $isWeekend ? null : 60,
                ]);
            }
        });
    }
}
