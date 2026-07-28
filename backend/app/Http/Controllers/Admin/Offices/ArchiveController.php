<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Offices;

use App\Actions\Offices\ArchiveOffice;
use App\Http\Requests\OfficeAdminRequest;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\JsonResponse;

final class ArchiveController
{
    public function __invoke(OfficeAdminRequest $request, Office $office, ArchiveOffice $action): JsonResponse
    {
        $archived = $action->execute($office);

        return OfficeResource::make($archived)->response();
    }
}
