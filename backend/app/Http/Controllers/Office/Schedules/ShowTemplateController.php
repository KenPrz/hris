<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Http\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowTemplateController
{
    public function __invoke(Request $request, ShiftTemplate $template): JsonResponse
    {
        // 404, not 403: a template whose office the caller doesn't administer must be
        // indistinguishable from a nonexistent {template} (which route-binding already
        // 404s on its own). The scope check lives here, not in a request, so both paths
        // land in the same NotFoundHttpException.
        if (! OfficeScope::administers($request->user(), $template->office_id)) {
            throw new NotFoundHttpException;
        }

        return ShiftTemplateResource::make($template->load('days'))->response();
    }
}
