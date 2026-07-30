<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeProfile> */
final class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'salutation' => 'Mr.',
            'nickname' => mb_strtoupper($this->faker->lexify('?????')),
            'home_address' => $this->faker->address(),
            'personal_email' => $this->faker->safeEmail(),
            'mobile' => '09'.$this->faker->numerify('#########'),
            'gender' => $this->faker->randomElement(Gender::cases()),
            'birth_date' => $this->faker->date('Y-m-d', '2004-01-01'),
            'birthplace' => $this->faker->city(),
            'marital_status' => $this->faker->randomElement(MaritalStatus::cases()),
            'citizenship' => 'Filipino',
            'religion' => 'Roman Catholic',
            'blood_type' => $this->faker->randomElement(BloodType::cases()),
        ];
    }
}
