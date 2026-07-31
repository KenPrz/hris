<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeIdentification;
use Illuminate\Support\Facades\DB;

/**
 * Deleting the model deletes its media through medialibrary's own model observer, so the
 * scan does not linger in RustFS as an orphan object nothing can reach.
 */
final class DeleteEmployeeIdentification
{
    public function execute(DeleteEmployeeIdentificationInput $in): void
    {
        DB::transaction(function () use ($in): void {
            EmployeeIdentification::query()->findOrFail($in->identificationId)->delete();
        });
    }
}
