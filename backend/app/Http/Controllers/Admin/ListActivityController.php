<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ListActivityRequest;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

final class ListActivityController
{
    /**
     * Laravel's default paginated-resource response wraps in {data, links, meta} — but
     * with absolute container URLs and an HTML-entity meta.links page array, which is
     * noise this API's single terse envelope doesn't carry anywhere else. Built by hand
     * instead: {data: [...activities], meta: {current_page, last_page, total, per_page}}.
     */
    public function __invoke(ListActivityRequest $request): JsonResponse
    {
        $logName = $request->string('log_name')->toString();
        $event = $request->string('event')->toString();
        $subjectType = $request->string('subject_type')->toString();
        $causerId = $request->string('causer_id')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $activities = Activity::query()
            ->when($logName !== '', fn ($q) => $q->where('log_name', $logName))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($subjectType !== '', fn ($q) => $q->where('subject_type', $subjectType))
            ->when($causerId !== '', fn ($q) => $q->where('causer_id', $causerId))
            ->when($from !== '', fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => ActivityResource::collection($activities->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
                'per_page' => $activities->perPage(),
            ],
        ]);
    }
}
