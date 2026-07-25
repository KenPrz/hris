<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PayRules;

use App\Http\Requests\PayRuleAdminRequest;
use App\Http\Resources\PayRuleResource;
use App\Models\PayRule;
use Illuminate\Http\JsonResponse;

final class ShowController
{
    public function __invoke(PayRuleAdminRequest $request, PayRule $payRule): JsonResponse
    {
        $payRule->load('dayRates');

        return PayRuleResource::make($payRule)->response();
    }
}
