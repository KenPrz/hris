<?php

declare(strict_types=1);

namespace App\Actions\PayRules;

use App\Domain\Pay\StatutoryFloor;
use App\Exceptions\Domain\PayRateBelowFloor;
use App\Exceptions\Domain\PayRuleExists;
use App\Models\PayRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates one pay-rule version: the scalar rates plus its five per-day-type rows.
 *
 * The floor check happens before the transaction opens — it is a pure, read-only
 * comparison against config('hris.pay_floors') (passed in by the controller so this
 * action's inputs stay explicit) and has nothing to do with the write. Only the
 * duplicate-effective_from check and the inserts themselves are transactional.
 */
final class CreatePayRule
{
    /** @param  array<string, mixed>  $floors  Same shape as config('hris.pay_floors'). */
    public function execute(CreatePayRuleInput $in, array $floors): PayRule
    {
        $violations = StatutoryFloor::violations($this->buildMatrix($in), $floors);

        if ($violations !== []) {
            throw new PayRateBelowFloor($violations);
        }

        return DB::transaction(function () use ($in): PayRule {
            // Unlike CreateHoliday there is no parent row to lockForUpdate() first — a pay
            // rule is a company singleton, not a child of some office row — so an unlocked
            // exists() pre-check would let two concurrent creates on the same date both pass
            // and the loser's insert 500 on the raw unique violation. Instead the
            // unique(effective_from) constraint IS the guard: try the insert and translate
            // its violation into the clean 409. This is race-safe and covers the sequential
            // duplicate identically (the second create hits the same constraint).
            try {
                $payRule = PayRule::query()->create([
                    'effective_from' => $in->effectiveFrom,
                    'overtime_ordinary_bp' => $in->overtimeOrdinaryBp,
                    'overtime_premium_bp' => $in->overtimePremiumBp,
                    'night_diff_bp' => $in->nightDiffBp,
                    'note' => $in->note,
                    'created_by' => $in->actorId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new PayRuleExists($in->effectiveFrom);
            }

            foreach ($in->dayRates as $rate) {
                $payRule->dayRates()->create([
                    'day_type' => $rate['day_type'],
                    'worked_bp' => $rate['worked_bp'],
                    'worked_rest_bp' => $rate['worked_rest_bp'],
                    'unworked_bp' => $rate['unworked_bp'],
                ]);
            }

            return $payRule->load('dayRates');
        });
    }

    /** @return array<string, mixed> Same shape as config('hris.pay_floors'). */
    private function buildMatrix(CreatePayRuleInput $in): array
    {
        $worked = [];
        $unworked = [];

        foreach ($in->dayRates as $rate) {
            $worked[$rate['day_type']] = [$rate['worked_bp'], $rate['worked_rest_bp']];
            $unworked[$rate['day_type']] = $rate['unworked_bp'];
        }

        return [
            'worked' => $worked,
            'unworked' => $unworked,
            'overtime_ordinary' => $in->overtimeOrdinaryBp,
            'overtime_premium' => $in->overtimePremiumBp,
            'night_diff' => $in->nightDiffBp,
        ];
    }
}
