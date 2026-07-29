<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Offices;

use App\Http\Requests\ListOfficesRequest;
use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListOfficesRequest $request): AnonymousResourceCollection
    {
        $includeArchived = $request->boolean('include_archived');
        $organization = $request->string('organization')->toString();

        $offices = Office::query()
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($organization !== '', fn ($q) => $q->where('organization_id', $organization))
            ->orderBy('name')
            ->get();

        return OfficeResource::collection($offices);
    }
}
