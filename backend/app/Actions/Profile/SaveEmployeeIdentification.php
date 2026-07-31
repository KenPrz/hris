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
        $identification = DB::transaction(function () use ($in): EmployeeIdentification {
            return EmployeeIdentification::query()->updateOrCreate(
                ['employee_id' => $in->employeeId, 'category_id' => $in->categoryId],
                [
                    'number' => $in->number,
                    'issued_on' => $in->issuedOn,
                    'expires_on' => $in->expiresOn,
                    'notes' => $in->notes,
                ],
            );
        });

        if ($in->scan !== null) {
            // Deliberately OUTSIDE the transaction above, and only after it has returned
            // (i.e. committed). singleFile() on the collection REPLACES any previous scan,
            // which means medialibrary deletes the OLD object from RustFS as part of adding
            // the new one. If that ran inside the transaction and something after it then
            // rolled the transaction back, the DB would restore a media row pointing at an
            // object that no longer exists — a permanently lost government-ID scan, the one
            // artifact this milestone says HR must be able to produce. Running it only once
            // the DB write is durable means a failure here can never corrupt a committed
            // row, and a rollback above can never reach RustFS at all.
            $identification->addMedia($in->scan)->toMediaCollection('scan');
        }

        return $identification->fresh();
    }
}
