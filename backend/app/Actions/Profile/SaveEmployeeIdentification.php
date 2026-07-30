<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeIdentification;
use Illuminate\Support\Facades\DB;

/**
 * Upsert on (employee_id, category_id) — the unique index the M10a schema carries, because
 * one employee has one TIN. A second write for the same category corrects the number rather
 * than adding a row.
 *
 * A null $scan LEAVES the existing scan alone; it does not clear it. Clearing a scan is
 * deleting the identification, which has its own route — "I only came to fix a typo in the
 * number" must never silently destroy the evidence HR is expected to produce.
 */
final class SaveEmployeeIdentification
{
    public function execute(SaveEmployeeIdentificationInput $in): EmployeeIdentification
    {
        return DB::transaction(function () use ($in): EmployeeIdentification {
            $identification = EmployeeIdentification::query()->updateOrCreate(
                ['employee_id' => $in->employeeId, 'category_id' => $in->categoryId],
                [
                    'number' => $in->number,
                    'issued_on' => $in->issuedOn,
                    'expires_on' => $in->expiresOn,
                    'notes' => $in->notes,
                ],
            );

            if ($in->scan !== null) {
                // singleFile() on the collection replaces any previous scan.
                $identification->addMedia($in->scan)->toMediaCollection('scan');
            }

            return $identification->fresh();
        });
    }
}
