<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CutoffPeriod;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CutoffPeriod> */
final class CutoffPeriodFactory extends Factory
{
    protected $model = CutoffPeriod::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-15',
            'state' => 'open',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['state' => 'closed', 'closed_at' => now()]);
    }
}
