<?php

declare(strict_types=1);

namespace App\Actions\Departments;

use App\Exceptions\Domain\DuplicateDepartmentCode;
use App\Models\Department;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates one department row. `departments.code` is unique per `(office_id, code)`
 * (confirmed against the M2 migration — scoped per office, unlike offices.code which is
 * global), so the pre-check below is scoped to office_id rather than global.
 *
 * The pre-check is the clean-422 path for the overwhelmingly common sequential case; the
 * try/catch around the insert is the race-safe backstop for a concurrent create that
 * slips past the pre-check between it and the insert (same pattern as CreateOffice::execute)
 * — so the worst case a client can observe is still a clean 422 DuplicateDepartmentCode,
 * never a raw unique-violation 500.
 *
 * Department is unguarded ($guarded = []), so the write is an explicit array rather than
 * a mass-assignment allowlist.
 */
final class CreateDepartment
{
    public function execute(CreateDepartmentInput $in): Department
    {
        return DB::transaction(function () use ($in): Department {
            if (Department::query()->where('office_id', $in->officeId)->where('code', $in->code)->exists()) {
                throw new DuplicateDepartmentCode($in->code);
            }

            try {
                return Department::query()->create([
                    'office_id' => $in->officeId,
                    'name' => $in->name,
                    'code' => $in->code,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateDepartmentCode($in->code);
            }
        });
    }
}
