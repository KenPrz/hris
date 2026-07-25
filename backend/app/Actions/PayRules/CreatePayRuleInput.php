<?php

declare(strict_types=1);

namespace App\Actions\PayRules;

final readonly class CreatePayRuleInput
{
    /**
     * @param  list<array{day_type: string, worked_bp: int, worked_rest_bp: int, unworked_bp: int}>  $dayRates
     *                                                                                                          Exactly the five DayType values, each once — CreatePayRuleRequest's
     *                                                                                                          withValidator after-hook is what guarantees that before this DTO is built.
     */
    public function __construct(
        public string $effectiveFrom,
        public int $overtimeOrdinaryBp,
        public int $overtimePremiumBp,
        public int $nightDiffBp,
        public array $dayRates,
        public ?string $note,
        public ?string $actorId,
    ) {}
}
