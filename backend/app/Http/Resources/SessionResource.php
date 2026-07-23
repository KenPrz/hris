<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\SessionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
            // Arr::only reads a subset of the employment cache columns; it never assigns
            // them. See ConventionsTest — only RecordEmploymentChange is allowed to write
            // those columns, and its grep is text-based, so this stays a plain read.
            'employee' => $s->employee === null ? null : Arr::only($s->employee->toArray(), [
                'id', 'employee_no', 'current_office_id', 'current_department_id',
            ]),
            'is_system_admin' => $s->isSystemAdmin,
            'has_reports' => $s->hasReports,
            'hr_offices' => $s->hrOffices,
            'permissions' => $s->permissions,
        ];
    }
}
