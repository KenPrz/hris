<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/**
 * The contract ApproveRequest depends on to resolve a RequestType's RequestEffect.
 * App\Actions\Requests\RequestEffectFactory is the one production implementation (bound in
 * AppServiceProvider); this interface exists solely so tests can substitute a spy/fake
 * without needing to mock a final class, mirroring the RequestEffect seam one level down
 * (interface in Domain, framework-touching implementation in Actions).
 */
interface RequestEffectResolver
{
    public function for(RequestType $type): RequestEffect;
}
