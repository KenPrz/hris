<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutoff;

use App\Domain\Cutoff\CutoffCalendar;
use App\Domain\Cutoff\CutoffState;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListCutoffsRequest;
use App\Http\Resources\CutoffPeriodResource;
use App\Models\CutoffPeriod;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListCutoffsController
{
    public function __invoke(ListCutoffsRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administered($request->user(), $request->string('office')->toString())
            ?? throw new NotFoundHttpException;

        $periods = CutoffPeriod::query()
            ->where('office_id', $office->id)
            ->orderBy('start_date')
            ->get();

        // A period row only exists once CloseCutoff has touched it — the office's current,
        // still-running window has none yet. Synthesize it (unpersisted; `id` is null) so
        // the frontend always has something to render for "now", never a gap between the
        // last stored row and today.
        $currentWindow = CutoffCalendar::windowFor(Date::now()->toDateString());

        $hasCurrentWindow = $periods->contains(
            fn (CutoffPeriod $period): bool => $period->start_date->toDateString() === $currentWindow['start']
        );

        if (! $hasCurrentWindow) {
            $periods->push(new CutoffPeriod([
                'office_id' => $office->id,
                'start_date' => $currentWindow['start'],
                'end_date' => $currentWindow['end'],
                'state' => CutoffState::Open->value,
            ]));
        }

        return CutoffPeriodResource::collection($periods);
    }
}
