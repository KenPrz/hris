<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\DeleteShiftTemplate;
use App\Domain\Scope\OfficeScope;
use App\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteTemplateController
{
    public function __invoke(Request $request, ShiftTemplate $template, DeleteShiftTemplate $action): Response
    {
        // 404, not 403: same office-scope discipline as ShowTemplateController — a
        // template whose office the caller doesn't administer 404s exactly like a
        // nonexistent {template}.
        if (! OfficeScope::administers($request->user(), $template->office_id)) {
            throw new NotFoundHttpException;
        }

        $action->execute($template);

        return response()->noContent();
    }
}
