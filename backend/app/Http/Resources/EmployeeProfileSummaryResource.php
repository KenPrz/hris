<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a MANAGER sees of a direct report: how to reach them and where they sit.
 *
 * Deliberately NOT a filtered EmployeeProfileResource. There is no `personal`, no
 * `dependents`, no `identifications`, and no `home_address` key — not a null one, no key at
 * all. A separate class means a new field added to the full resource cannot leak here by
 * default; someone has to come and add it on purpose.
 *
 * @mixin Employee
 */
final class EmployeeProfileSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $current = EmploymentResolver::on($this->resource, EmployeeLocalToday::for($this->resource));

        return [
            'employee_id' => $this->id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,

            'contact' => [
                'personal_email' => $profile?->personal_email,
                'phone' => $profile?->phone,
                'mobile' => $profile?->mobile,
            ],

            'assignment' => EmployeeAssignmentPresenter::forEmployee($this->resource, $current),
        ];
    }
}
