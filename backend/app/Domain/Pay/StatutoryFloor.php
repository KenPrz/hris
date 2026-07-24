<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * Compares a proposed pay-rule matrix against the statutory floor matrix, cell by cell.
 *
 * Pure and framework-agnostic: both matrices arrive as arguments, nothing is read from
 * config and nothing is thrown here — the caller (an Action) turns a non-empty result
 * into PayRateBelowFloor. A cell is a violation only when it is strictly below floor;
 * sitting exactly at the floor is compliant.
 */
final class StatutoryFloor
{
    /**
     * @param  array<string, mixed>  $proposed  Same shape as config('hris.pay_floors').
     * @param  array<string, mixed>  $floors  The statutory floor matrix.
     * @return list<FloorViolation>
     */
    public static function violations(array $proposed, array $floors): array
    {
        $violations = [];

        foreach ($floors['worked'] as $dayType => [$notRestFloor, $restFloor]) {
            [$notRestProposed, $restProposed] = $proposed['worked'][$dayType];

            if ($notRestProposed < $notRestFloor) {
                $violations[] = new FloorViolation("worked.{$dayType}.not_rest", $notRestProposed, $notRestFloor);
            }

            if ($restProposed < $restFloor) {
                $violations[] = new FloorViolation("worked.{$dayType}.rest", $restProposed, $restFloor);
            }
        }

        foreach ($floors['unworked'] as $dayType => $floorBp) {
            $proposedBp = $proposed['unworked'][$dayType];

            if ($proposedBp < $floorBp) {
                $violations[] = new FloorViolation("unworked.{$dayType}", $proposedBp, $floorBp);
            }
        }

        foreach (['overtime_ordinary', 'overtime_premium', 'night_diff'] as $scalar) {
            if ($proposed[$scalar] < $floors[$scalar]) {
                $violations[] = new FloorViolation($scalar, $proposed[$scalar], $floors[$scalar]);
            }
        }

        return $violations;
    }
}
