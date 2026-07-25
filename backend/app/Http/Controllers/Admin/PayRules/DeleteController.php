<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PayRules;

use App\Exceptions\Domain\PayRuleInUse;
use App\Http\Requests\PayRuleAdminRequest;
use App\Models\DailyAttendanceSummary;
use App\Models\PayRule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;

final class DeleteController
{
    public function __invoke(PayRuleAdminRequest $request, PayRule $payRule): Response
    {
        // Pre-check for the common case: a clean 409 instead of ever reaching for the
        // constraint. Not race-safe alone — a summary could get stamped with this
        // version between the check and the delete — so the catch below is the real
        // backstop.
        if (DailyAttendanceSummary::query()->where('rule_version_id', $payRule->id)->exists()) {
            throw new PayRuleInUse($payRule->id);
        }

        // DB FK (rule_version_id, ON DELETE RESTRICT) refuses the delete outright while
        // any daily_attendance_summaries row still references this version — a summary
        // priced against a version that then vanished would leave its lines pointing at
        // nothing. pay_rule_day_rates cascade-deletes; versions are otherwise immutable
        // (no PATCH route), so delete is the only write this record sees after creation.
        try {
            $payRule->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                throw new PayRuleInUse($payRule->id);
            }

            throw $e;
        }

        return response()->noContent();
    }
}
