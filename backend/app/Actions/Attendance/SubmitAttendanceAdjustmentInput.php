<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use Illuminate\Http\UploadedFile;

final readonly class SubmitAttendanceAdjustmentInput
{
    public function __construct(
        public string $employeeId,
        public AdjustmentOperation $operation,
        public string $note,
        public ?string $targetLogId,
        public ?PunchDirection $direction,
        public ?string $punchedAt,
        public ?UploadedFile $attachment,
    ) {}
}
