<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Domain\Pay\FloorViolation;

/**
 * Thrown when a proposed pay rule sets one or more multipliers below the statutory floor
 * (config('hris.pay_floors'), Task 1). Carries every violating cell so the caller can show
 * all of them at once instead of failing one field at a time.
 */
final class PayRateBelowFloor extends DomainException
{
    /** @param  list<FloorViolation>  $violations */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('One or more proposed rates fall below the statutory floor.');
    }

    public function errorCode(): string
    {
        return 'pay_rate_below_floor';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'violations' => array_map(
                fn (FloorViolation $v) => [
                    'multiplier' => $v->multiplier,
                    'proposed_bp' => $v->proposedBp,
                    'floor_bp' => $v->floorBp,
                ],
                $this->violations,
            ),
        ];
    }
}
