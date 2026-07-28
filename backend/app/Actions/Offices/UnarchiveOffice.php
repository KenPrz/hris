<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Exceptions\Domain\NotArchived;
use App\Models\Office;
use Illuminate\Support\Facades\DB;

final class UnarchiveOffice
{
    public function execute(Office $office): Office
    {
        return DB::transaction(function () use ($office): Office {
            if ($office->archived_at === null) {
                throw new NotArchived('office', $office->id);
            }

            $office->update(['archived_at' => null]);

            return $office;
        });
    }
}
