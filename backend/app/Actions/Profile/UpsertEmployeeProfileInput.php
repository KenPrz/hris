<?php

declare(strict_types=1);

namespace App\Actions\Profile;

/** Every field nullable: a personnel file is filled in over time, not in one sitting. */
final readonly class UpsertEmployeeProfileInput
{
    public function __construct(
        public string $employeeId,
        public ?string $salutation = null,
        public ?string $nickname = null,
        public ?string $homeAddress = null,
        public ?string $personalEmail = null,
        public ?string $phone = null,
        public ?string $fax = null,
        public ?string $mobile = null,
        public ?string $emergencyContact = null,
        public ?string $gender = null,          // Domain\Profile\Gender value
        public ?string $birthDate = null,       // 'YYYY-MM-DD'
        public ?string $birthplace = null,
        public ?string $maritalStatus = null,   // Domain\Profile\MaritalStatus value
        public ?string $citizenship = null,
        public ?string $religion = null,
        public ?string $bloodType = null,       // Domain\Profile\BloodType value
    ) {}
}
