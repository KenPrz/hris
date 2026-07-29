<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Organizations;

use App\Http\Requests\ListOrganizationsRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(Organization::query()->orderBy('name')->get());
    }
}
