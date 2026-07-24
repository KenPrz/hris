<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PayRules;

use App\Actions\PayRules\CreatePayRule;
use App\Actions\PayRules\CreatePayRuleInput;
use App\Http\Requests\CreatePayRuleRequest;
use App\Http\Resources\PayRuleResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreatePayRuleRequest $request, CreatePayRule $action): JsonResponse
    {
        $dayRates = collect($request->input('day_rates'))
            ->map(fn (array $rate): array => [
                'day_type' => (string) $rate['day_type'],
                'worked_bp' => (int) $rate['worked_bp'],
                'worked_rest_bp' => (int) $rate['worked_rest_bp'],
                'unworked_bp' => (int) $rate['unworked_bp'],
            ])
            ->all();

        $payRule = $action->execute(
            new CreatePayRuleInput(
                effectiveFrom: $request->string('effective_from')->toString(),
                overtimeOrdinaryBp: (int) $request->input('overtime_ordinary_bp'),
                overtimePremiumBp: (int) $request->input('overtime_premium_bp'),
                nightDiffBp: (int) $request->input('night_diff_bp'),
                dayRates: $dayRates,
                note: $request->input('note'),
                actorId: $request->user()->id,
            ),
            config('hris.pay_floors'),
        );

        return PayRuleResource::make($payRule)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
