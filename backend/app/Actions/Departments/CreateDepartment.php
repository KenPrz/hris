<?php

declare(strict_types=1);

namespace App\Actions\Departments;

use App\Exceptions\Domain\DuplicateDepartmentCode;
use App\Exceptions\Domain\InvalidReference;
use App\Models\Department;
use App\Models\Office;
use Illuminate\Database\QueryException;
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
 * CreateDepartmentRequest is deliberately shape-only on office_id (no `exists:`), so a
 * nonexistent parent must be turned into a clean 422 here rather than reaching the FK
 * constraint raw — same pre-check + try/catch-backstop shape as CreateOffice's
 * organization_id guard, this time against office_id.
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

            if (! Office::query()->whereKey($in->officeId)->exists()) {
                throw new InvalidReference('office', $in->officeId);
            }

            try {
                return Department::query()->create([
                    'office_id' => $in->officeId,
                    'name' => $in->name,
                    'code' => $in->code,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateDepartmentCode($in->code);
            } catch (QueryException $e) {
                if ($e->getCode() === '23503') {
                    throw new InvalidReference('office', $in->officeId);
                }

                throw $e;
            }
        });
    }
}
