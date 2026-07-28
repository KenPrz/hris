<?php

declare(strict_types=1);

namespace App\Actions\Departments;

use App\Exceptions\Domain\NotArchived;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

final class UnarchiveDepartment
{
    public function execute(Department $department): Department
    {
        return DB::transaction(function () use ($department): Department {
            if ($department->archived_at === null) {
                throw new NotArchived('department', $department->id);
            }

            $department->update(['archived_at' => null]);

            return $department;
        });
    }
}
