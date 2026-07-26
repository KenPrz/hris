<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Actions\Requests\Effects\AttendanceAdjustmentEffect;
use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestType;
use LogicException;

/**
 * Maps a RequestType to its RequestEffect, resolved from the container so each effect gets
 * its own dependencies injected. An unmapped type is a programming error — a request type
 * reached approval with no effect wired — never a silent no-op approve.
 */
final class RequestEffectFactory
{
    public function for(RequestType $type): RequestEffect
    {
        return match ($type) {
            RequestType::AttendanceAdjustment => app(AttendanceAdjustmentEffect::class),
            default => throw new LogicException("No RequestEffect registered for request type {$type->value}."),
        };
    }
}
