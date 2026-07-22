<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\System\HealthStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HealthStatus
 */
final class HealthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var HealthStatus $status */
        $status = $this->resource;

        return [
            'healthy' => $status->isHealthy(),
            'app_version' => $status->appVersion,
            'database' => [
                'ok' => $status->databaseOk,
                'version' => $status->databaseVersion,
                'reason' => $status->failureReason,
            ],
        ];
    }
}
