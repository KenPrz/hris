<?php

declare(strict_types=1);

namespace App\Actions\Departments;

use App\Exceptions\Domain\AlreadyArchived;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Archive-never-delete for the org tree: sets `archived_at`, never removes the row.
 * Reuses the generic AlreadyArchived exception (subjectType 'department') introduced by
 * Office's ArchiveOffice — the error shape is identical regardless of which table owns
 * the row.
 */
final class ArchiveDepartment
{
    public function execute(Department $department): Department
    {
        return DB::transaction(function () use ($department): Department {
            if ($department->archived_at !== null) {
                throw new AlreadyArchived('department', $department->id);
            }

            $department->update(['archived_at' => now()]);

            return $department;
        });
    }
}
