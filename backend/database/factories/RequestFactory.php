<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Request> */
final class RequestFactory extends Factory
{
    protected $model = Request::class;

    public function definition(): array
    {
        return [
            'type' => RequestType::AttendanceAdjustment,
            'employee_id' => Employee::factory(),
            'state' => RequestState::Pending,
            'note' => $this->faker->sentence(),
        ];
    }
}
