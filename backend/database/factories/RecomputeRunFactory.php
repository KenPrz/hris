<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Compute\RecomputeTrigger;
use App\Models\RecomputeRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecomputeRun> */
final class RecomputeRunFactory extends Factory
{
    protected $model = RecomputeRun::class;

    /** A finished holiday-triggered run by default. */
    public function definition(): array
    {
        return [
            'trigger_type' => RecomputeTrigger::Holiday,
            'trigger_id' => null,
            'reason' => $this->faker->sentence(),
            'pair_count' => $this->faker->numberBetween(0, 25),
            'batch_id' => null,
            'status' => 'completed',
            'caused_by' => null,
        ];
    }

    /** Still in flight — a run row written before its batch has finished. */
    public function queued(): static
    {
        return $this->state(fn (): array => ['status' => 'queued']);
    }

    /** A run whose batch reported a failure. */
    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => 'failed']);
    }
}
