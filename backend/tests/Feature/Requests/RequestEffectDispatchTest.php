<?php

declare(strict_types=1);

use App\Actions\Requests\RequestEffectFactory;
use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestType;

it('resolves the attendance-adjustment effect for its type', function (): void {
    $effect = app(RequestEffectFactory::class)->for(RequestType::AttendanceAdjustment);

    expect($effect)->toBeInstanceOf(RequestEffect::class);
});
