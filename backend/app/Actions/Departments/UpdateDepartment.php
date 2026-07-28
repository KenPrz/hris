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
 * Updates one department row. Re-checks the per-office unique `(office_id, code)` only
 * when the office/code combination actually changed (excluding this department's own
 * row), same reasoning as UpdateOffice: a pre-check for the clean 422 in the common
 * case, a try/catch backstop around the write for the race a concurrent update could
 * still slip through.
 *
 * The full-object update sets office_id unconditionally, so the same changed-parent
 * guard is needed here as CreateDepartment: only re-check existence when the incoming
 * office_id actually differs from the current one, backstopped by a catch on the
 * FK-violation SQLSTATE (23503) for the concurrent-delete race.
 */
final class UpdateDepartment
{
    public function execute(UpdateDepartmentInput $in): Department
    {
        return DB::transaction(function () use ($in): Department {
            $department = Department::query()->findOrFail($in->departmentId);

            if (($in->code !== $department->code || $in->officeId !== $department->office_id)
                && Department::query()
                    ->where('office_id', $in->officeId)
                    ->where('code', $in->code)
                    ->where('id', '!=', $department->id)
                    ->exists()
            ) {
                throw new DuplicateDepartmentCode($in->code);
            }

            if ($in->officeId !== $department->office_id
                && ! Office::query()->whereKey($in->officeId)->exists()
            ) {
                throw new InvalidReference('office', $in->officeId);
            }

            try {
                $department->fill([
                    'office_id' => $in->officeId,
                    'name' => $in->name,
                    'code' => $in->code,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateDepartmentCode($in->code);
            } catch (QueryException $e) {
                if ($e->getCode() === '23503') {
                    throw new InvalidReference('office', $in->officeId);
                }

                throw $e;
            }

            return $department;
        });
    }
}
