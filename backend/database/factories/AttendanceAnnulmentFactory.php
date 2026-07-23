<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceAnnulment> */
final class AttendanceAnnulmentFactory extends Factory
{
    protected $model = AttendanceAnnulment::class;

    public function definition(): array
    {
        return [
            'attendance_log_id' => AttendanceLog::factory(),
            'request_id' => Request::factory(),
            'created_at' => now(),
        ];
    }
}
