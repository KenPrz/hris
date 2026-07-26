<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Schedule\Weekday;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftTemplateDay> */
final class ShiftTemplateDayFactory extends Factory
{
    protected $model = ShiftTemplateDay::class;

    public function definition(): array
    {
        return [
            'shift_template_id' => ShiftTemplate::factory(),
            'weekday' => Weekday::Monday,
            'is_rest' => false,
            'start_minute' => 480,  // 08:00
            'end_minute' => 1080,   // 18:00
            'break_minutes' => 60,
        ];
    }

    /** A rest day: no hours, the minute columns null (the DB CHECK requires this XOR). */
    public function rest(): static
    {
        return $this->state(fn (): array => [
            'is_rest' => true,
            'start_minute' => null,
            'end_minute' => null,
            'break_minutes' => null,
        ]);
    }
}
