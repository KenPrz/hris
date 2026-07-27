<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Actions\Requests\Effects\AttendanceAdjustmentEffect;
use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestEffectResolver;
use App\Domain\Requests\RequestType;
use LogicException;

/**
 * Maps a RequestType to its RequestEffect, resolved from the container so each effect gets
 * its own dependencies injected. An unmapped type is a programming error — a request type
 * reached approval with no effect wired — never a silent no-op approve.
 *
 * Implements RequestEffectResolver (bound in AppServiceProvider) rather than being
 * type-hinted directly by its consumers, so tests can bind a spy/fake in its place — this
 * class is final, like every Action, and Mockery cannot satisfy a concrete final
 * type-hint.
 */
final class RequestEffectFactory implements RequestEffectResolver
{
    public function for(RequestType $type): RequestEffect
    {
        return match ($type) {
            RequestType::AttendanceAdjustment => app(AttendanceAdjustmentEffect::class),
            default => throw new LogicException("No RequestEffect registered for request type {$type->value}."),
        };
    }
}
