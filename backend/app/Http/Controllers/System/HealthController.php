<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Actions\System\CheckHealth;
use App\Http\Resources\HealthResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-action controller: the entire HTTP layer for one system action.
 * See docs/04-backend-conventions.md.
 *
 * Health has no request body, so there is no FormRequest and no Input DTO — those
 * exist to validate, authorize, and map, and there is nothing here to validate.
 * Endpoints that take input add them.
 */
final class HealthController
{
    public function __invoke(CheckHealth $action): JsonResponse
    {
        $status = $action->execute();

        // Health is the one endpoint that maps a domain result to a status code rather
        // than throwing: monitoring needs a body describing the failure either way.
        return HealthResource::make($status)
            ->response()
            ->setStatusCode($status->isHealthy()
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
