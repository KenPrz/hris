<?php

declare(strict_types=1);

use App\Domain\Requests\RequestType;

it('flags leave as the two-hop (manager -> HR) type and attendance adjustment as single-hop', function (): void {
    expect(RequestType::AttendanceAdjustment->requiresHrStep())->toBeFalse()
        ->and(RequestType::Leave->requiresHrStep())->toBeTrue();
});

it('marks overtime as a single-hop type', function (): void {
    expect(RequestType::Overtime->requiresHrStep())->toBeFalse();
    expect(RequestType::Overtime->value)->toBe('overtime');
});
