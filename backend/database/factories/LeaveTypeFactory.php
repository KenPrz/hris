<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
final class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => 'Vacation Leave',
            'code' => null,
            'is_paid' => true,
            'requires_attachment' => false,
            'deducts_balance' => true,
            'is_cash_convertible' => false,
            'max_carryover_minutes' => null,
            'is_active' => true,
        ];
    }
}
