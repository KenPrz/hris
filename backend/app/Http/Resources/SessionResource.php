<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\SessionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SessionData */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SessionData $s */
        $s = $this->resource;

        return [
            'user' => [
                'id' => $s->user->id,
                'email' => $s->user->email,
                'name' => $s->user->name,
            ],
            'employee' => $s->employee === null ? null : [
                'id' => $s->employee->id,
                'employee_no' => $s->employee->employee_no,
                'current_office_id' => $s->employee->current_office_id,
                'current_department_id' => $s->employee->current_department_id,
            ],
            'is_system_admin' => $s->isSystemAdmin,
            'has_reports' => $s->hasReports,
            'hr_offices' => $s->hrOffices,
            'permissions' => $s->permissions,
        ];
    }
}
