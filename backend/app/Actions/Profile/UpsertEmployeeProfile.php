<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;

/**
 * Upsert rather than update: the 1:1 row does not exist until someone first fills the
 * personnel file in, and CreateEmployee deliberately does not pre-create an empty one.
 *
 * A PUT replaces the whole profile — an omitted field becomes null rather than keeping its
 * old value. That is what PUT means, and it keeps "clear this employee's fax number" from
 * needing its own endpoint.
 */
final class UpsertEmployeeProfile
{
    public function execute(UpsertEmployeeProfileInput $in): EmployeeProfile
    {
        return DB::transaction(fn (): EmployeeProfile => EmployeeProfile::query()->updateOrCreate(
            ['employee_id' => $in->employeeId],
            [
                'salutation' => $in->salutation,
                'nickname' => $in->nickname,
                'home_address' => $in->homeAddress,
                'personal_email' => $in->personalEmail,
                'phone' => $in->phone,
                'fax' => $in->fax,
                'mobile' => $in->mobile,
                'emergency_contact' => $in->emergencyContact,
                'gender' => $in->gender,
                'birth_date' => $in->birthDate,
                'birthplace' => $in->birthplace,
                'marital_status' => $in->maritalStatus,
                'citizenship' => $in->citizenship,
                'religion' => $in->religion,
                'blood_type' => $in->bloodType,
            ],
        ));
    }
}
