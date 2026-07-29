<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Offices;

use App\Actions\Offices\UnarchiveOffice;
use App\Http\Requests\OfficeAdminRequest;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\JsonResponse;

final class UnarchiveController
{
    public function __invoke(OfficeAdminRequest $request, Office $office, UnarchiveOffice $action): JsonResponse
    {
        $unarchived = $action->execute($office);

        return OfficeResource::make($unarchived)->response();
    }
}
