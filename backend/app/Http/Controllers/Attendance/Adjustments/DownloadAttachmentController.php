<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Domain\Requests\RequestAuthority;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadAttachmentController
{
    // Same visibility boundary as ShowController (requester or an authorized approver),
    // then a private stream through the app — never a public/object URL, and 404 (not an
    // empty body) when the request exists but carries no attachment, so neither branch
    // leaks anything an unrelated caller couldn't already infer from a plain 404.
    public function __invoke(HttpRequest $http, Request $request): StreamedResponse
    {
        $employee = $http->user()->employee;

        $isRequester = $employee !== null && $request->employee_id === $employee->id;

        if (! $isRequester && ! RequestAuthority::canDecide($http->user(), $request)) {
            throw new NotFoundHttpException();
        }

        $media = $request->getFirstMedia('attachment');

        if ($media === null) {
            throw new NotFoundHttpException();
        }

        return $media->toResponse($http);
    }
}
