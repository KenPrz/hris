<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeDependent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replace-all. A dependent list is short, owned entirely by HR, and referenced by nothing
 * else, so one PUT beats POST/PATCH/DELETE plus the id bookkeeping they force on the client.
 *
 * The delete is scoped by employee_id, never a truncate — another employee's dependents are
 * not this request's business.
 */
final class ReplaceEmployeeDependents
{
    /** @return Collection<int, EmployeeDependent> */
    public function execute(ReplaceEmployeeDependentsInput $in): Collection
    {
        return DB::transaction(function () use ($in): Collection {
            // Deliberately row-by-row, not a query-builder bulk delete. A query-builder
            // `delete()` fires no model events, so LogsActivity never records the removal.
            // The max:20 validation bound on dependents makes N individual deletes acceptable.
            EmployeeDependent::query()
                ->where('employee_id', $in->employeeId)
                ->get()
                ->each(fn (EmployeeDependent $dependent): bool => $dependent->delete());

            return collect($in->dependents)->map(fn (array $row): EmployeeDependent => EmployeeDependent::query()->create([
                'employee_id' => $in->employeeId,
                'name' => $row['name'],
                'relationship_id' => $row['relationship_id'],
                'birth_date' => $row['birth_date'] ?? null,
            ]))->values();
        });
    }
}
