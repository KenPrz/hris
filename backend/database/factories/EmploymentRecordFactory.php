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
        return [
            'employee_id' => Employee::factory(),
            'effective_from' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            // office_id/department_id resolve lazily in configure()'s afterMaking below —
            // never eagerly here. A plain PHP statement in definition() (the old
            // `Office::factory()->create()`) runs unconditionally, before any ->create()
            // override is merged in, so a caller-supplied office_id/department_id (every
            // real caller supplies both — see support.php's computeEmployee()) still left
            // an orphaned Office+Organization+Department triple behind: nothing in the
            // returned array ever referenced it, so it was never wired to anything the
            // caller could clean up. Harmless under RefreshDatabase (the transaction
            // rollback erases it too), but fatal for a test that commits its fixtures for
            // real (CloseVsRecomputeConcurrencyTest) — the orphan survives the test and
            // leaks into the shared database.
            'office_id' => null,
            'department_id' => null,
            'reports_to_id' => null,
            'employment_type' => 'regular',
            'is_art82_exempt' => false,
            'base_rate_cents' => 61000, // ~PHP 610/day, near the NCR minimum
        ];
    }

    public function configure(): static
    {
        // Only materialize a default Office+Department pair when the caller didn't supply
        // its own via ->create(['office_id' => ..., 'department_id' => ...]) — resolved
        // after overrides are merged (afterMaking sees the final attributes), so nothing
        // is created unless it's actually needed.
        return $this->afterMaking(function (EmploymentRecord $record): void {
            if ($record->office_id === null) {
                $record->office_id = Office::factory()->create()->id;
            }

            if ($record->department_id === null) {
                $record->department_id = Department::factory()->create(['office_id' => $record->office_id])->id;
            }
        });
    }
}
