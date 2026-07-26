<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveLedger> */
final class LeaveLedgerFactory extends Factory
{
    protected $model = LeaveLedger::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'entry_type' => 'credit',
            'minutes' => 480,
            'reason' => 'Manual grant',
            'source' => 'manual_grant',
            'request_id' => null,
            'created_by' => User::factory(),
            'created_at' => now(),
        ];
    }
}
