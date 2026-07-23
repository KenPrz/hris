<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Attendance\AdjustmentOperation;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceAdjustmentDetail> */
final class AttendanceAdjustmentDetailFactory extends Factory
{
    protected $model = AttendanceAdjustmentDetail::class;

    public function definition(): array
    {
        return [
            'request_id' => Request::factory(),
            'operation' => AdjustmentOperation::Add,
            'target_log_id' => null,
            'direction' => null,
            'punched_at' => null,
        ];
    }
}
