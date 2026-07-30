<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The whole personnel file — self and in-scope HR Admin only.
 *
 * Its counterpart EmployeeProfileSummaryResource is a SEPARATE class rather than a
 * conditional in here, so that adding a field to this resource can never silently widen
 * what a manager sees. See the M10a spec.
 *
 * @mixin Employee
 */
final class EmployeeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $current = EmploymentResolver::on($this->resource, Carbon::today());

        return [
            'employee_id' => $this->id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,

            'details' => [
                'salutation' => $profile?->salutation,
                'first_name' => $this->first_name,
                'middle_name' => $this->middle_name,
                'last_name' => $this->last_name,
                'name_suffix' => $this->name_suffix,
                'nickname' => $profile?->nickname,
            ],

            'contact' => [
                'home_address' => $profile?->home_address,
                'personal_email' => $profile?->personal_email,
                'phone' => $profile?->phone,
                'fax' => $profile?->fax,
                'mobile' => $profile?->mobile,
                'emergency_contact' => $profile?->emergency_contact,
            ],

            'personal' => [
                'gender' => $profile?->gender?->value,
                'birth_date' => $profile?->birth_date?->toDateString(),
                'age' => $profile?->age,
                'birthplace' => $profile?->birthplace,
                'marital_status' => $profile?->marital_status?->value,
                'citizenship' => $profile?->citizenship,
                'religion' => $profile?->religion,
                'blood_type' => $profile?->blood_type?->value,
            ],

            'assignment' => EmployeeAssignmentPresenter::forEmployee($this->resource, $current),

            'dependents' => $this->dependents->map(fn ($dependent): array => [
                'id' => $dependent->id,
                'name' => $dependent->name,
                'relationship' => $dependent->relationship?->code,
                'birth_date' => $dependent->birth_date?->toDateString(),
            ])->values()->all(),

            'identifications' => $this->identifications->map(fn ($identification): array => [
                'id' => $identification->id,
                'category_code' => $identification->category?->code,
                'category_name' => $identification->category?->name,
                'number' => $identification->number,
                'issued_on' => $identification->issued_on?->toDateString(),
                'expires_on' => $identification->expires_on?->toDateString(),
                'notes' => $identification->notes,
                // Never a URL: the scan is an app-mediated stream. This flag only tells the
                // client whether the stream route will return something.
                'has_scan' => $identification->hasMedia('scan'),
            ])->values()->all(),
        ];
    }
}
