<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Exceptions\Domain\AlreadyArchived;
use App\Models\Office;
use Illuminate\Support\Facades\DB;

/**
 * Archive-never-delete for the org tree: sets `archived_at`, never removes the row.
 * Non-cascading — an archived office keeps its departments untouched (M8a design).
 */
final class ArchiveOffice
{
    public function execute(Office $office): Office
    {
        return DB::transaction(function () use ($office): Office {
            if ($office->archived_at !== null) {
                throw new AlreadyArchived('office', $office->id);
            }

            $office->update(['archived_at' => now()]);

            return $office;
        });
    }
}
