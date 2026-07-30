<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A private, app-mediated stream — never a public or presigned object URL. RustFS publishes
 * no ports in production precisely because every attachment goes out through here.
 *
 * Gated on viewFullProfile, NOT viewRedactedProfile: a manager gets the redacted resource,
 * which never hands them an identification id, so a manager reaching this route at all is
 * either a guess or an attack. Same 404-for-everything shape as DownloadAttachmentController.
 */
final class DownloadScanController
{
    public function __invoke(Request $request, Employee $employee, EmployeeIdentification $identification): StreamedResponse
    {
        if ($identification->employee_id !== $employee->id) {
            throw new NotFoundHttpException();
        }

        if ($request->user()->cannot('viewFullProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $media = $identification->getFirstMedia('scan');

        if ($media === null) {
            throw new NotFoundHttpException();
        }

        return $media->toResponse($request);
    }
}
