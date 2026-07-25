<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PayRules;

use App\Http\Requests\ListPayRulesRequest;
use App\Http\Resources\PayRuleResource;
use App\Models\PayRule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListPayRulesRequest $request): AnonymousResourceCollection
    {
        $payRules = PayRule::with('dayRates')->orderByDesc('effective_from')->get();

        return PayRuleResource::collection($payRules);
    }
}
