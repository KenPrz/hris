<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceLog> */
final class AttendanceLogFactory extends Factory
{
    protected $model = AttendanceLog::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'office_id' => Office::factory(),
            'punched_at' => now(),
            'direction' => PunchDirection::In,
            'source' => PunchSource::Web,
            'verification' => PunchVerification::Verified,
            'flag_reason' => null,
            'created_at' => now(),
        ];
    }
}
