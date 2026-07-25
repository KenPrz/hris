<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PayRules;

use App\Http\Requests\PayRuleAdminRequest;
use App\Models\PayRule;
use Illuminate\Http\Response;

final class DeleteController
{
    public function __invoke(PayRuleAdminRequest $request, PayRule $payRule): Response
    {
        // DB FK (on delete cascade) removes the pay_rule_day_rates rows; versions are
        // immutable (no PATCH route), so delete is the only write this record ever sees
        // after creation.
        $payRule->delete();

        return response()->noContent();
    }
}
